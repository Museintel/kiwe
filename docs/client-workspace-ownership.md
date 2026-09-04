# Client workspace and ownership — RC49

## Authority model

This is a site-configured access policy, not an Ascendants-specific fork. Installing RC48 does not turn it on. An existing full administrator activates it at Users → Access & ownership, naming their own signed-in account as the sole owner. On a single site, Kiwe's Super Admin is a full-capability owner role; it does not convert WordPress to Multisite. On Multisite, activation requires exactly one existing native network Super Admin and maintains that native singleton.

The owner can transfer to an existing Administrator using their current WordPress password and a nonce-protected confirmation. A unique options-table lock prevents concurrent changes. Failed persistence restores the earlier assignments. Transfer retains both users, makes the previous owner a client Administrator, and then permits normal deletion of that former owner. The current owner cannot be deleted, demoted, granted a competing owner role, or have credentials edited by a client Administrator. Core user/meta, role assignment, REST and network-owner paths are covered. A hosting/database operator remains outside an in-WordPress security boundary.

## Roles

| Role | Workspace |
| --- | --- |
| Super Admin | All native and installed-plugin capabilities, technical Kiwe controls, ownership and role migration |
| Administrator | Posts, Media, Comments, Users, native Pages (view, create titled drafts, change indexing; no existing content edits/deletion) and business sections |
| Editor | Edit/publish existing posts; cannot create posts |
| Author | Create/edit/publish own posts using native object ownership checks |
| Contributor | Existing native own-draft permissions retained pending product definition |
| Shop manager | Product authoring only; assignable only with active commerce |
| Subscriber | Low-friction frontend account; no wp-admin |
| User | Verified frontend account; no wp-admin |
| Customer | Purchase-completed frontend account; no wp-admin |

User is stored as `kiwe_user`. Existing identity evidence remains in Key.kiwe; login/factor-verification events promote only a pure Subscriber. Purchase completion converts only Subscriber/User roles, never staff roles. Checkout's existing server-side verification boundary remains authoritative.

Extra roles are unavailable for new assignment after activation. Owner-only migration records prior definitions and per-user assignments before removing a role. Occupied roles require an explicit target; accounts and content are never deleted by role retirement. Plugins can recreate their own definitions, but assignment remains filtered.

## Business sections

Identity, Story, Contact, Brand, Website plan, Services, and conditional Store are top-level WordPress menus. The old account-bound onboarding links remain valid as hidden aliases. There is no Review page. Each form posts only its section, merges current native data, passes the maintained sanitizer, and calls only that section's native writers. Website plan now links to WordPress Pages without a duplicate inventory or page planner; stored legacy notes are preserved, and actual pages are inferred from WordPress. Developer-owned service-source binding is not editable by clients. Native CPT/term/meta permissions still apply to bound services; this policy does not grant unrestricted third-party CPT authority.

WordPress Media Library remains the resource catalog. Team controls remain native user fields with separate `kiwe_manage_team` authority. Public context continues to whitelist public profile information; credentials and private account details are not exported. Business saves do not run site-wide SEO scans synchronously.

## Plugin integration and enforcement

Bricks 2.3.10 source confirms that Administrators get default access and raw grants are cached at init. Kiwe's active owner policy restricts WordPress capability checks and clamps Bricks' cached full/edit/code/SVG/submission flags for nonowners before its normal initialization. Builder URLs, AJAX/REST mutations and Bricks metadata writes remain blocked. Stored role definitions and theme files are not rewritten; public forms, rendering and query routes remain available. Users displays effective builder access. Ownership must first be activated; installation never silently claims an owner.

Rank Math has an owner-selected mode: post editing controls (default), its dashboard, or off. Inline updates require an editable Post object and allow only SEO-prefixed metadata/permalink. Pages remain non-editable, including through SEO endpoints. Other technical plugin dashboards are owner-only; this is not a generic arbitrary-capability granting interface.

## Native Pages

The native WordPress list supplies page IDs, search, pagination and statuses. Its `edit_pages` primitive is granted solely for list access; object-level editing, deletion, revisions and generic metadata writes remain denied. Add Page goes to a small title/indexing form, which calls core `wp_insert_post` with a forced draft, empty content and current author. Submitted IDs, content, types, authors and arbitrary metadata are ignored. A designer builds and publishes the draft. Details/indexing is a small per-page form, not a second inventory or content editor.

Indexing actions require a management capability and per-page nonce. They change no title, content, layout, status or author. On Rank Math sites, native `rank_math_robots` retains all other directives; the existing Kiwe visibility field mirrors that choice for native sitemaps/context. Changes in Rank Math also update the mirror. Only the affected page and Rank Math page-sitemap caches are purged on save. Allow-index does not override draft/private status, site-wide search discouragement, provider exclusions, or search engines' decisions.

`test-native-pages.php` adds creation, forged-input, nonce and indexing checks. Passing the Bricks directory to `client-workspace-contracts.cjs` executes its real permission classes as well (tested locally with the supplied 2.3.10 source). These tests stub WordPress I/O and do not replace signed-in live role-session tests.

Capability filtering, native object checks, direct-admin-route checks, AJAX and REST mutation boundaries enforce the workspace. Hiding menus alone is not the protection. Key account/reset/verification flows and frontend saved items, notifications, hydration, metrics and commerce endpoints retain their own nonce/session/permission checks. The policy remains registered even if the optional Key login module is disabled.

## Verification and recovery

`tools/release/test-client-workspace.php` executes the actual policy against stubbed WordPress I/O, including failed transfer rollback and generic-metadata bypass attempts. It is not a replacement for real WordPress role-session testing. The owner panel has a read-only effective-capability table against actual existing accounts.

The full regression runner includes the ownership test, native context save tests, independent-section browser-JS tests, and the pre-existing Key/commerce/security tests. Before activation, confirm the full-access owner and preserve a hosting recovery path. The protected MU updater retains the prior package. On a single site, an authorized hosting operator can temporarily disable the policy with WP-CLI: `wp option patch update kiwe_access_policy_v1 enabled false`. This does not undo deliberate role migrations; their recovery records are retained inside that option. Never delete the option or owner account as a recovery shortcut.
