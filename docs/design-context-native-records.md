12 October candidate: [Resumable setup](DESIGN-CONTEXT-JOURNEY.md) adds shared progress and applicability without another CMS. Services keeps its original flag; explicit Team visibility complements retained native selection. Private preferences stay in the existing encrypted configuration recovery unit.

**Current UI build, 10 September:** [One DSA Menu, Guest work and notification coverage](GUEST-MENU-NOTIFICATION-COVERAGE-2026-09-10.md) correct the invented two-menu UI, add applications/review/own Guest contributions, and expose all 19 built-in notification IDs conditionally with per-type sample channel choices. Administrator Home has 16 destinations; role/site-specific views differ. 180 browser checks and 22 sample boundary checks passed; a 22-area source/coverage ledger records missing journeys. The existing bounded server notification queue was found; the prior claim that only session history exists is corrected. Native role/read-history integration and per-type channel persistence remain pending. Runtime RC83 guard off; lab RC82; Ascendants RC55.4. No runtime/package change, signing, commit, push or deployment.

**10 September UI migration:** [RC83](WORKSPACE-SHELL-RC83-2026-09-10.md) keeps the existing identity inputs, records, media picker and save handlers while redesigning their presentation. [Full coverage](DESIGN-CONTEXT-WORKSPACE-COVERAGE.md) separates operational UI, curated AI context and approved public facts. Existing fields may appear in useful header/card/task locations; one native authority and one editing home remain required.

# Design Context: native WordPress records (RC47)

**RC58 amendment:** [RC58 Design library and Navigation](DESIGN-LIBRARY-NAVIGATION-2026-09-06.md) makes explicit selection authoritative for the general media pool and approved testimonials. Design library owns those choices; native Media and Comments own uploads, metadata and moderation. Teams has its own central RC56 workspace. The historical RC47 account below remains background, and does not imply that every public media record belongs in current Design Context.

Design Context is a client-friendly editing surface, not a second CMS. Its existing page slug remains `kiwe-onboarding`, while the menu label is **Kiwe → Design Context**. SiteGraph and Ideate continue consuming the same versioned context and existing dynamic bindings.

## Where clients manage information

| Information | Editing surface | Authoritative record |
| --- | --- | --- |
| Site name, tagline, logo, icon, timezone | Design Context / existing WordPress settings | Native WordPress and existing Kiwe identity options |
| Store settings | Store step, only with commerce available | WooCommerce options on WooCommerce sites |
| Services | Home → Services; saved connected records also feed Design Context | Configured native/public service post type, or a retained unbound plan |
| Images, video, audio, documents | WordPress → Media | Native attachments; no second resource list |
| Public team membership and job title | WordPress → Users → Edit user | Existing Kiwe user metadata; administrator-controlled |
| Team name, biography, website, portrait and social links | WordPress user profile / DSA Profile | The same WordPress user and approved metadata |

The Store step is detected from active WooCommerce, never the chosen site-type label or a leftover product post type. Other commerce adapters can explicitly opt in using `kiwe_design_context_commerce_available`; they must implement their own native settings mapping. Kiwe does not write WooCommerce settings when WooCommerce is inactive.

Services start disabled on new sites. Previously configured services remain enabled to avoid breaking existing sites. Turning Services off retains its plan and does not delete or update service posts. Enabling a bound source reuses the existing post, taxonomy and permitted custom-field editing logic.

Unbound Service plans have a separate reviewed native conversion. The shared Services dialog creates only WordPress drafts through the same Service writer and retains an encrypted non-autoloaded recovery transaction. Guarded undo refuses changed drafts, native versions, metadata, category assignments or known foreign references, and restores only the original Service section. It never replaces a configured external source or deletes existing media. See [the conversion authority/recovery decision](decisions/2026-10-03-reviewed-service-plan-conversion.md). [RC249 native Service writing history](SERVICE-HISTORY-RC249-2026-10-03.md) now reuses WordPress saved revisions and actor recovery for the configured eligible source. Context, native per-record permission, revision support, exact proofs and guarded three-field transactions remain authoritative. The shared Edit/Organize/SEO journey and Timeline compare complete writing without changing current publication, terms, media, SEO, Profile or encrypted conversion recovery. Existing Organize field controls remain; common taxonomy/group selector acceptance and browser confirmation actions are still open. Operational backup coverage is a separate unfinished unit.

## Teams and privacy

Administrators select **Show as a team member** and set **Team title** on a user's WordPress profile. On forms with a Role selector, these controls sit immediately below it. Eligibility is based on content/administration capabilities, not account verification level: ordinary Subscribers and Customers cannot join the public team. The Users list shows a Public team column.

Staff can update biography, website, LinkedIn and Facebook from the expandable **Public profile** area inside the existing DSA profile sheet/screen. Name and avatar continue using their existing controls. They cannot self-select into the team or change their assigned title. Core user contact fields reuse `kiwe_linkedin_url` and `facebook`; no second contact record is created.

Public team output is an explicit allowlist: stable member ID, user reference, display name, biography, job title, portrait, website, LinkedIn, Facebook and ordering. No login email/phone, password, session, passkey, role/capability, private account setting or arbitrary plugin metadata is exported. Deselecting or losing eligible capabilities removes a user from the resolved team. This does not remove native WordPress author archives or other independently published information.

Existing `kiwe_team_*` Bricks tags, stable selectors and `kiwe/team` block bindings remain valid for selected users. SiteGraph supplies a native WP_User_Query include-list of eligible selected IDs; an empty team uses `[0]`, never an empty include-list that would return every user.

Legacy unlinked team entries and old resource notes remain in the saved option for recovery, but are not exposed as a second editor or public team roster. No WordPress users or media attachments are deleted. Administrators must select actual staff user records to publish a team.

## Media and performance

The existing SiteGraph media catalog now considers every MIME type, not images alone. It remains bounded and searchable, with pagination through the existing media query (`resource: media`, `mimeType: ""`, `page`, `limit`). A context packet is a selection, not an unbounded dump of the entire library. Larger libraries remain accessible page-by-page.

Public catalog queries exclude private attachments and attachments belonging to draft/private/password-protected content. Unattached ordinary Media Library uploads are public WordPress assets; confidential files must not be uploaded as public attachments. This does not bypass third-party protected-download systems or grant rights to reuse copyrighted assets.

There is no duplicate media catalog, polling service, external API or new frontend request. RC339 adds only a bounded rebuildable embedded-image hint on original content; native relationships remain authoritative. See [Media context and Trash](MEDIA-CONTEXT-AND-TRASH.md). Disabled services skip catalog resolution and post mutation. WordPress's user-query and metadata caches support the selected-team projection.

## Verification and rollout

Run:

```text
node tools/release/design-context-native-contracts.cjs
node tools/release/onboarding-contracts.cjs
node tools/release/sitegraph-design-context-contracts.cjs
node tools/runtime/profile-menu-geometry-contracts.cjs
```

Before production rollout, test the actual WordPress Users form and DSA profile under an administrator and an author, check a selected team member's existing Bricks loop, save a non-commerce Design Context, and repeat with WooCommerce enabled in staging. Existing published content, login verification and checkout are not changed by this feature.
