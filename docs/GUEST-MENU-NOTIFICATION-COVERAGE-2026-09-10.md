# One DSA Menu, Guest work and notification coverage

10 September 2026. The founder corrects the preview's invented “Main menu” versus “Visitor DSA menu” distinction: the client manages one DSA Menu screen. They require visible Guest application/review tasks, all relevant notification types with chosen delivery, and a dashboard that presents only the work each role can perform. Existing Kiwe capabilities and the documented vision must determine coverage.

This unit changes the **local synthetic React preview and documentation only**. Administrator Home has 16 destinations; the total catalog has 17, including the Guest-only My contributions route. Final browser acceptance passed **180 checks**, with **22 sample transition/boundary checks** and a static inventory matching **all 19 built-in notification topic IDs** across **22 feature areas**. Runtime candidate RC83 remains guarded off; lab RC82; Ascendants RC55.4. No PHP/package/version change, commit, push, signing or deployment.

## Corrected client tasks

There is one **Show in DSA Menu** switch on page/category/service cards. Navigation previews that same DSA Menu screen; Home and Identity use the same selection. No independent `selected` membership remains on the sample navigation records. WordPress menus can still be the native source behind that screen. [Assets.php](../wp-content/mu-plugins/dsa/includes/Public_Endpoint/Assets.php) reads configured `menu_nav_ids` / `menu_nav_id`, and custom DSA items remain another supported source. Native connection must edit the configured source without inventing a second client menu or altering unrelated theme menus.

Guest work is split into tasks with different authorities:

| Preview role | Guest work and other visibility |
| --- | --- |
| Client Administrator | Guest applications with pending/approved/denied views, approve/decline and decision note, contribution enablement; article/product review; existing business/client areas. Approval grants sample Contributor/Guest submission access, never automatic publication or store management. |
| Editor | Posts for editing/review, Media, Review contributions for articles, Notifications. No new-post action, page/category settings, applications, People, store or global identity. This corrects the broader earlier sample to Kiwe's current review-focused Editor policy. |
| Author | Own posts, own media and relevant Notifications. No Guest application decisions, review queue or global business settings. |
| Shop manager | Product work and product-proposal review, commerce areas, media and relevant Notifications; no editorial or application decisions. **The intended commerce UI is ahead of native owner-policy capabilities for orders/settings and some client product operations; resolve those gates before connection.** |
| Guest (WordPress Contributor) | My contributions and Notifications only. Own draft/pending/published records, new contribution when approved and enabled, no publication action, no administrative data/tasks. Drafts and submitted work are read-only after sending, matching existing Kiwe behavior. |

[Guest_Contribution_Service](../wp-content/mu-plugins/dsa/includes/Access/Guest_Contribution_Service.php) already owns application metadata, verified eligibility, enabled intents, decisions, adding/removing Contributor, and immutable Guest submissions. Its writer only creates draft or pending native posts/products. [WordPress_Role_Access_Service](../wp-content/mu-plugins/dsa/includes/Access/WordPress_Role_Access_Service.php) owns effective capabilities and native object restrictions. The preview adds no WordPress role or capability.

Guest contributions and reviewer edits use the sample's existing post/product records, not a parallel editorial store. The reviewer can choose a matching category, keep a draft or approve and publish in the sample. Guest status then reads that same record. Review and submission transitions produce recipient-specific sample notification updates. Native integration must reuse WordPress post-status events, not send a second notification from the React button.

All sample role checks and direct-hash denial are presentation/interaction evidence. Real security requires server-filtered task/read models, per-object authorization, nonce/session handling, limited returned fields, concurrency checks and native write/readback. Never ship the sample bootstrap, which contains synthetic records for multiple roles, as a production authorization implementation.

## Types first, with delivery on the event card

Notifications now has an explicit **notification types** view. Work events and the account's own reader/customer subscriptions have separate headings. Each event card has Notify me and channel choices; default channels are available in a focused sheet. Changing an order event's delivery does not alter Guest application delivery. Personal offers/cart/news subscriptions start opted out. SMS and disabled visitor telemetry remain unavailable; none of these controls alter essential authentication.

The inventory covers all 19 IDs in the native `Notification_Preference_Service::topics()` implementation: Guest applications/submissions/product proposals; Guest article/application decisions and own product decisions; Author article status; new orders/comments; security incidents; visitor alerts/summary; publication newsletters/posts; offers, price, stock, new products, own order status and saved-cart reminders. Role and Woo/non-Woo conditions determine which appear. The preview's site selector demonstrates both site profiles. Runtime filters can extend this list; the connected UI must consume the server's catalog rather than freeze these 19 IDs.

**Per-event channel overrides are new sample behavior, not an existing deployed capability.** Current native preferences store topic and channel lists; `user_accepts()` checks membership in both. The UI's `topicChannels` map is memory-only. If adopted in the connected application, extend the existing preference authority, sanitization, recipient lookup and dispatcher together, with legacy default-channel fallback. Do not add a second notification settings store or claim per-type delivery works merely because a checkbox saves locally. Verified contacts, browser permission/subscription state, provider readiness and explicit consent still govern actual delivery.

## Correction to the previous inbox audit

The [previous card report](WORKSPACE-CARD-CONTROLS-2026-09-10.md) inspected session history and overstated the missing server foundation. [Admin_Event_Notification_Service](../wp-content/mu-plugins/dsa/includes/Notifications/Admin_Event_Notification_Service.php) already persists a per-user queue under `dsa_admin_notification_inbox`, caps stored entries at 40, returns up to 20 pending entries, and records acknowledgments. Sources include Guest applications, decisions/submissions, content status, orders/comments and security ingress. This existing service must be reused.

The remaining issue is the complete read/history contract: `pull_current_user()` currently requires manage_options, manage_woocommerce or moderate_comments, which excludes several intended restricted roles; it also distinguishes delivery/acknowledgment rather than providing the sample's full read/unread history API. DSA additionally keeps up to 30 session-local history items. Resolve eligibility, durable read state versus acknowledgment, retention and concurrent updates before claiming full cross-device behavior. Do not add a new inbox table or automatic polling on the assumption that no server queue exists. This unit changes none of that runtime behavior.

## Coverage is now explicit

[feature-coverage.json](../tools/workspace-prototype/feature-coverage.json) maps 22 areas to source owners and UI/integration status. [audit-coverage.cjs](../tools/workspace-prototype/audit-coverage.cjs) verifies referenced files, extracts the native built-in topic IDs, compares them to the sample, records source hash and emits role/task lists. Its receipt is static evidence, not end-to-end proof.

The dashboard does **not** yet cover the entire Kiwe ecosystem. Major existing-source areas still needing complete React journeys include AI setup/allowance, SEO jobs/proposals, campaigns/audiences, verified account/recovery, saved items and PWA/device management. Product variants, full orders/payments/refunds, editor/Bricks compatibility, native media/moderation and user/profile boundaries remain incomplete. Service taxonomy hierarchy/multiple terms and native archive indexing also need adapters. Native source existence does not prove those workflows are launch-ready.

Backup/restore and Drive offloading remain a separate planned product; existing AI rollback capture is not equivalent. POS, bookings, membership, attendance, LMS, cross-site games/SSO and the entrepreneur network remain roadmap work. Seam/Bricks generation is a separate ecosystem workflow. The product vision remains intact; the narrower UI catalog must never be presented as its completion.

## Acceptance and continuation

External evidence: `work/guest-menu-evidence/coverage.json`, `pass-1/browser.json` (178 passed), and `pass-2/browser.json` (180 passed). Visual inspection found a disabled-SMS selector muting entire notification cards; that CSS was corrected. The second pass also checks the previously missed Identity-to-DSA Menu projection. Guest cards and final notification type cards were visually inspected on phone/desktop. Browser checks exercise single-menu state, existing task edits, application decisions, immutable own Guest work, editor review/publication, recipient updates, per-type/default channels, opt-out, role omissions/direct URLs, Woo/non-Woo examples, disabled contribution types and responsive widths. The 22 deterministic sample checks exercise denied role transitions, stale/unverified applications, wrong taxonomy/type, missing approval/enablement and forced-publication prevention.

Build, static inventory, grouped browser checks, documentation links and diff formatting are verified for this local unit. PHP 8.2/native WordPress/provider/load/physical-device acceptance remains unverified for the React integration; unchanged runtime suites were not repeated. Branch remains `main` at baseline `731a94ff48dd350bb7b168ec285fcc96afbdaa01`, preserving accumulated edits. No changes in Key or Seam. Evidence and generated review copies remain outside source.

Next: use this coverage ledger for the connected People/Identity/task sequence. Add the missing existing-source journeys deliberately, and resolve role/inbox/native ownership gaps as part of that connection. Continue grouped tests; do not make another unqualified “complete ecosystem” claim from a sample screen count.
