**Subsequent approved expansion:** The founder approved this corrected visual family. [The full core UI pass](FULL-WORKSPACE-UI-2026-09-10.md) extends it to Home, content, commerce and the full permitted People profile; 100 local checks passed. This report preserves the earlier 38-check correction scope.

# Metro workspace: the tiles are the working surface

10 September 2026. The founder rejected the RC83 form-oriented visual treatment and supplied the app-style dashboard brief. This correction supersedes that UI direction, not its historical test receipts or the underlying native services. The opt-in RC83 runtime remains off by default. Current source candidate RC83; lab RC82; Ascendants RC55.4. This unit changes local preview tooling and documentation only.

## Product contract from the founder's brief

The private workspace is an application inside WordPress: Windows Phone/Lumia/Windows 8 Metro proportions, flat colored tiles, clear typography, visible useful state and shallow navigation. A tile may open a focused editor for its subject; the main screen must not be an old grouped form with decorative cards above it. Mobile, tablet and desktop are first-class layouts. Preserve visible Home/back navigation and keyboard access rather than recreating hidden OS gestures.

| Area | Required interaction and authority |
| --- | --- |
| Identity | Client Administrator taps the actual site icon to change the favicon, or the name to edit it. Name, tagline, main logo and adjacent dark logo have one editing home. Existing WordPress/Kiwe fields feed configured public bindings. Editors, Authors and Store Managers do not receive global brand editing controls. |
| Design Context | Identity, contact/social/location, story/audience, design preferences, pages and their purpose, SEO/meta, Woo setup, services, selected media/testimonials and selected people all belong in the everyday workspace. Selection is explicit; the whole user/media/comment library is not automatically Design Context. |
| People | Show permitted existing WordPress users and select who is on the public team. Membership, public title and display order already have native owners and Bricks bindings. Never create a second team roster or change a person's permission role by selecting them. |
| Daily content | Posts/pages/products/categories/services should show the object and relevant actions together: rename, add, permitted presentation removal, navigation/DSA inclusion, search appearance. Changing a menu label is different from renaming its source page/category. Preserve those semantics. |
| Roles | Server capabilities supply the permitted task manifest. Client Administrator gets business control; Editor gets permitted posts/pages; Author gets their own content; Store Manager gets permitted products/orders/inventory. Omit unauthorized tasks instead of showing disabled versions. A preview role selector is not authorization. |
| Sensitive actions | System-critical deletion, account-role changes, plugin setup and structural tools remain outside the client surface. “Remove” is not authorization for permanent deletion or a new permission grant. Preserve records when changing presentation membership. |
| Home and future modules | Greet the business and surface real permitted pending items. POS, booking and future modules should add task tiles through the manifest. Counts, previews, empty states and badges require authoritative data, not animation or fabricated activity. |
| Shared-host speed | Private application assets must add no bytes to ordinary public pages. No per-tile polling or new visitor logging to make tiles look live. Twenty simultaneous backend users with no public slowdown is a target for measured native acceptance, not a verified guarantee. |

The brief says the foundation is built. Reuse inspected services first, while retaining known implementation gaps: native editor coverage, Woo owner/client capabilities, icon clearing, full shipping/tax editors and other unresolved cases in [coverage](DESIGN-CONTEXT-WORKSPACE-COVERAGE.md). The brief does not turn those gaps into passed evidence.

## What changed in this unit

The local React preview at `http://127.0.0.1:8774/` now opens a fifteen-tile website workspace. It displays the saved sample name/tagline, image states, selected team and media counts, menu labels and business type. Name, tagline, main/dark logos, favicon and business classification open focused editors. The header's icon and name directly open their corresponding editor. Identity changes update the sample tiles, header, local favicon and visitor light/dark identity. Escape/cancel protects unsaved changes and returns keyboard focus to the activating tile.

People is now a tile workspace for six synthetic existing accounts, with readable account roles, public titles and direct “Show on team” checkboxes. Selection immediately updates the sample team count and preview. Undo, all-users/on-team views, name search, empty search and person details are provided. This operates on the existing sample user IDs; roles remain read-only. Account creation and a separate team catalog are absent.

The previous grouped Identity component remains historical source but is no longer imported. Its stylesheet is removed from the active bundle. Home retains the tile composition, uses flat corners and greets the sample business. Non-client role previews omit identity controls. All saves remain in browser memory; reloading resets the sample. No customer data, WordPress session or live API is connected.

Other website tiles currently expose their mapped scope and explicitly state that detailed editing is still being designed. Posts/products and media/navigation retain earlier sample interactions; they are not accepted as final Metro task designs. This unit does not deliver the full dashboard or native production UI. Do not keep adding disconnected feature simulations indefinitely: the next useful work is a native reference slice of these corrected interactions.

## Existing Team connection to reuse

- [Team_Workspace_Service](../wp-content/mu-plugins/dsa/includes/Access/Team_Workspace_Service.php) owns the bounded user listing, eligibility, object checks, expected-state fingerprint and save/readback. Current native listing is paged staff selection, not unrestricted access to every account.
- [Design_Context_Profile_Service](../wp-content/mu-plugins/dsa/includes/Onboarding/Design_Context_Profile_Service.php) owns `kiwe_public_team_member`, `kiwe_team_title` and `kiwe_team_order`. Selecting a user must preserve their account, role and other profile fields.
- Existing Bricks team tags and the query projection consume these records. Confirm actual template bindings and cache invalidation on the lab; a sample roster changing is not proof of public readback.

The next adapter must reuse this writer with native session/nonce/capability checks, bounded reads, stale-write rejection and confirmed saved-state responses. Keep the draft or previous membership on error. Do not replace this with an unguarded generic user-meta endpoint. Any mismatch between “all users with access” and current native eligibility requires an explicit authority decision before broadening the listing.

## Tile and interaction contract

The prototype's `Tile` primitive accepts a color family, size/layout kind, accessible title/label, data-derived content and an action. Use wide identity, tall team and compact image/fact variants, then reflow to two columns on phones. Labels and content communicate meaning without depending on color. Native dialogs provide focus containment; explicit close/cancel and Escape have the same draft semantics. Motion is optional and reduced-motion is respected.

For the connected component, add explicit loading, unavailable, empty, notification-badge and error states using the same owner-supplied model. These full lifecycle states are still required; the sample count and empty search state do not implement that whole contract. Reuse a single permitted bootstrap/current-screen response rather than one query per tile. The [shell decision](decisions/2026-09-10-tile-workspace-shell.md) retains same-origin PHP delivery, native WordPress authority and independent Bricks public rendering.

## Verification and limits

One grouped browser acceptance passed **38 checks**: direct identity edits, saved-state propagation, dirty Escape/cancel/discard and focus return, image/favicon behavior, selected-user projection and undo, role preservation, filters/search/empty state, role-specific omissions including direct hash navigation, deferred task JavaScript, no external requests and no JavaScript errors. Layouts fit 1440, 820, 390 and 360 pixels without horizontal overflow. Desktop and phone screenshots were visually inspected. This is not full accessibility, physical-device or native WordPress acceptance.

The first run stopped at an ambiguous test file-input locator (dialog and input shared an accessible name). The locator was scoped to the input and the grouped run passed; no application assertion was removed. The initial receipt is retained. The reusable [UI check](../tools/workspace-prototype/check-ui.cjs) records source hashes, runtime, requests and results outside the repository; [run instructions](../tools/workspace-prototype/README.md) are portable.

Final in-memory build: Home JavaScript including its shared chunk is 74,043 gzip bytes; shared CSS is 6,604 gzip bytes. Tasks add 2,432 gzip bytes and the Metro module 5,284 gzip bytes on demand. These are computed compressed sizes, not measured network transfer; the loopback server sends uncompressed responses. No new public-site asset is shipped. These values do not establish shared-host capacity.

Evidence is under the external task workspace `work/metro-workspace-evidence/`: `browser.json`, `browser-initial-locator.json`, `server.stdout.log`, desktop/tablet/phone images and the final handoff receipt. Workspace verification found the expected pre-existing dirty working trees; branch/origin/upstream remained correct. Preserve them. Documentation links and diff formatting are checked with this handoff. No unchanged PHP/runtime baseline was rerun for this preview-only unit.

## Continuation

Lead next: connect the corrected People tiles to the existing native team writer, then Identity to its current owners. Gate the private document and task manifest on real capabilities; resolve the narrow Woo owner/client gaps; cover save failure, stale state, expired session, unauthorized users, membership/title preservation and actual public binding readback in one lab pass. Keep RC83's old form bridge off as the rollout default. Full editor and commerce journeys remain release gates.

Continue subject-by-subject through categories and their menu/SEO relationships, selected media/testimonials, pages/posts, products, services and business setup. The whole-context inventory remains the checklist. A category/menu adapter must use the original records, not copied labels or a second settings model.

Canonical branch `main`, baseline HEAD `731a94ff48dd350bb7b168ec285fcc96afbdaa01`. Uncommitted local tooling/docs only; no package/version changes, signing, push or deployment. No schema, capability, credential, telemetry, PhoneKey, passkey or live setting changes. Lab remains RC82 and Ascendants RC55.4. Recovery for this sample is reload/reset or stopping its loopback process; production recovery is unaffected.
