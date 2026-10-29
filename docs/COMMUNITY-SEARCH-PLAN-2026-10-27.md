# Tag-aware Search and contextual Comments DSA

Date:27 October2026; closed29 October. Status: **CF1–CF8 accepted; Kiwe1 is published on all three named sites and shared Start/Seam. Zero community implementation or hosted rollout/cleanup steps remain.** [Final acceptance, recovery and limits](KIWE-1-LIVE-2026-10-29.md). The earlier plans and intermediate checkpoints below remain historical.

## Historical planning record — 27–28 October

The following sections retain their original planning and intermediate-status language for traceability. The closed status and final acceptance above govern current work; old remaining-batch counts and approval requirements do not reopen completed work.

Initial implementation evidence: [native foundation and composer development](COMMENTS-LAB-DEVELOPMENT-2026-10-28.md). Native source and isolated UI checks were qualified separately from the later signed installed lab and founder experience acceptance.

Latest founder clarification: Daily Feedback is **one newest-first timeline**, with like counts on every comment/review card. Filters are Everything, Posts, Website and, only when Store is applicable, Products. Do not restore separate product/post activity spaces or replace the stream with analytics. Preserve native moderation, testimonial selection and focused-notification access to the full stream. [Current ownership decision](decisions/2026-10-28-native-discussion-and-phonekey-reuse.md).

## Current scope — reuse PhoneKey

The latest founder instruction takes precedence over the earlier broad CF2 proposal: PhoneKey already supplies account creation, low-friction entry and verification. Preserve that tested engine. Its ordinary visitor heading changes from **Continue securely** to **Subscribe Now**; the comment sheet reuses its existing account, contact verification and OTP delivery interfaces. Do not create a new authentication system, alter signup-role policy, redesign passkey/recovery flows, add a separate verification state or perform an account/role migration as part of this programme.

In the founder's product language, an ordinary low-verification entrant is a **Subscriber**, and proving an email or phone advances participation to **Partial** so their eligible comments can publish immediately. Current canonical PhoneKey already defaults new signups to the native Subscriber role; a missing Kiwe label is not evidence that native role provisioning must change. Expose the appropriate existing role/assurance information clearly, preserving customers, staff and configured roles. Verification changes assurance on the existing account, not its WordPress privileges.

Comments remain a welcoming way to capture names and email/phone contacts in the existing native account/contact owners for later Campaign audiences. Capture source and verification status through existing owners where supported; do not create a parallel lead database or silently change delivery preferences. This does not start the future campaign providers, MCP, Google or Meta Apps.

An unverified Subscriber can submit for moderation. The composer offers OTP for immediate publication under the site's existing policies, or Skip for moderation. Partially/fully verified eligible sessions reuse their established assurance. Existing account protection, native moderation and purchase restrictions remain integration constraints; they are not a new PhoneKey rewrite. Any demonstrated gap must be documented precisely before widening that scope.

The programme is **five lab development/qualification batches plus one final publication/three-site rollout**. CF2 is a small copy/integration contract inside the same execution batch as CF3; stable CF IDs and all requested community outcomes remain traceable below. The founder's later **lets work on comment batches** instruction resumes implementation and supersedes the earlier documentation-only pause. All three production sites and, until a qualified candidate is installed, the lab remain on accepted Core450. After the complete lab journey is reviewed, CF8 prepares the Kiwe 1 launch with existing update/App compatibility preserved. MCP, Google and Meta follow.

Earlier 28 October direction, retained for chronology and narrowed by the current scope above: complete the entire Comments/Reviews journey on the lab before production publication. The founder asked about PhoneKey changes before implementation. The previous broad public-name/authentication/recovery proposal is superseded by reuse of established PhoneKey interfaces and a heading change. Keep all three production sites on accepted Core450 during development. After the complete experience is reviewed against the founder's vision, CF8 qualifies and publishes the named three-site Kiwe 1 launch. Qualify release naming, existing Core/App dependency admission and native update/version comparisons before changing any runtime version identifier. This planning changes no runtime or live setting and claims no CF2 outcome.

28 October composer clarification supersedes the earlier separate join-sheet recommendation: the ordinary PhoneKey entry should say **Subscribe Now** and retain the permitted low-assurance subscriber journey for ordinary activity. The discussion sheet itself says **Join the conversation** and collects the comment plus missing public name/contact. Send starts inline PhoneKey contact verification; a valid OTP completes the explicitly requested submission once, while Skip sends an unverified submission to moderation where native policy allows. One proven email or phone is sufficient participation assurance (current PhoneKey Partial); full security setup is not the default requirement for an ordinary commenter. The detailed identity, moderation and private Feedback rules below govern account collisions and native restrictions. This is the accepted lab implementation direction, not a claim that the new flow is already implemented.

The founder previously authorized CF1, named deployment to all three sites and the Super Admin-only staff dashboard choice. Signed Core448 was accepted and closed on the lab, National, Dharwad and Ascendants; the subsequent accepted baseline is Core450. Rank Math remains on Ascendants and Store is absent there. [CF1 behavior, migration, checks and recovery](CF1-SEARCH-DASHBOARD-RC448-2026-10-27.md). Comments implementation is now underway locally; a visible DSA, complete lab acceptance and production publication are not yet claimed. APP120 acceptance and the separate shared performance, Google/Meta/MCP and Ascendants SEO/AdSense programmes remain intact.

## Intent and recommendations

1. Search should automatically include content whose assigned public tags match what a visitor types. Do not require a Tag filter or another pill. Preserve the existing Latest/Popular sorting choices.
2. Provide one Comments DSA, with a context-specific title: Comments on posts, Reviews on products, and optional Site feedback elsewhere. Core owns shared discussion presentation and WordPress integration; Store alone contributes product rating and purchase-policy logic.
3. Keep native WordPress comments and WooCommerce reviews as the records of authority. Extend existing Daily Feedback and notification owners rather than making parallel stores or moderation dashboards.
4. Separate public name, contact ownership, account authority, purchase verification and marketing consent. A low-friction signup must not be treated as proof of someone else's email, phone or orders.
5. Load lists and composers on demand. Preserve native policy, accessibility, existing templates and commerce speed. Standard and attributed controls use the same Seam contract.

The founder's requested outcomes are recorded here; decisions described as recommendations are not yet approved product behavior.

## Confirmed source baseline before CF1

| Current owner | Observed behavior and limitation |
| --- | --- |
| `includes/Search/Search_Service.php` | Nonempty text is passed as native `WP_Query` search argument `s`. There is no assigned-tag content matching. Results are bounded to 6 by default and 12 maximum; the returned count is not an exhaustive result total. Current term-change hooks do not explicitly cover all term-relationship assignment/removal paths. |
| `includes/Rest/Search_Controller.php` | The public search endpoint already has rate limiting and differentiated caching. Extend this owner, retaining public/private cache separation and current scope applicability. |
| `includes/Access/Tag_Group_Service.php` | Kiwe tag groups are organizational folders over native `post_tag` and `product_tag`; native terms and assignments retain identity. Group names are not automatically public search keywords. |
| `includes/Access/Comment_Moderation_Service.php` | Daily Feedback already reads native comments/reviews, applies native moderation status and can select testimonials. Permissions use native capabilities; product feedback respects Store applicability. It does not yet provide the proposed public discussion/reaction journey. |
| `includes/Access/Continuity_App_Service.php` | Feedback already has a Kiwe workspace endpoint and route. Current operations cover moderation and testimonial selection. Extend these for authorized replies and other approved actions. |
| `includes/Notifications/Admin_Event_Notification_Service.php` | A native comment event already queues admin notifications. Its comment action currently points to native comment editing. Extend it to a focused Kiwe Feedback item while retaining access to the complete stream. Audit lifecycle deduplication and spam admission before extending events. |
| `includes/PhoneKey/phonekey-core.php` | Native Subscriber is the default signup role. The current verify-later shortcut is limited to a new, low-risk account with unused grace; existing accounts require proof. Newly created email-based users currently receive an email-local-part display-name fallback; phone users receive a generic fallback. Neither is a sufficient public-identity policy. |
| `assets/js/modules/profile-panel.js` | First name, last name and a public display-name field already exist. Reuse the public-name owner and avoid forcing a full profile journey before participating. |
| `includes/Link_Hub/Review_Service.php` | This is a separate testimonial/external-review presentation feature, not the authority for Woo product reviews. Do not turn configured testimonial text, fallback ratings or imported review snippets into native product ratings. |

Paths in this table are relative to `wp-content/mu-plugins/dsa/`. Findings concern the canonical current source; this planning turn does not diagnose the exact deployed Ascendants version or change it. An eventual Ascendants delivery must follow its existing source/recovery/provider migration programme.

## CF1: normal typing finds tagged content

Use a scoped union of eligible native text matches and eligible assigned-tag matches. A plain `tax_query` added alongside `s` would usually intersect the two and still miss tag-only posts; the implementation must prove the intended OR semantics while keeping visibility restrictions outside that union.

- Posts match native post tags. Products match native product tags only when Store is applicable. Other supported public taxonomies need an explicit allowed-content mapping; do not index private administration fields or tag-folder labels by accident.
- Match tag names/slugs with bounded, escaped lookup. Define phrase, case and Unicode behavior against native database collation and test it; do not promise semantic search, transliteration or fuzzy matches without an implementation.
- Deduplicate text/tag matches before ordering. Keep the visitor's selected Latest/Popular order; tag matching changes which results qualify, not the meaning of the selected sort.
- Keep a finite first response and provide a bounded Show more/cursor path so all eligible tagged results are reachable. Do not label the first six results as the complete total.
- Reuse current public visibility checks. Never expose private, draft, password-protected or Store-inapplicable content through matching terms. Internal search eligibility must not be confused with search-engine `noindex` policy.
- Invalidate on tag assignment/removal, term edits/deletion and content visibility changes, including native editor, bulk/API and Woo paths. Preserve the current public/private cache boundary.
- Avoid a full content scan or per-post tag requests on every keystroke. Retain debounce, cancellation/stale-response protection and existing endpoint abuse controls. Measure candidate queries and compare latency/query counts with the current service.

An unobtrusive matched-tag explanation may be useful, but no extra visitor filter is required.

## One contextual screen and control contract

| Visitor context | Proposed screen and Dock behavior |
| --- | --- |
| Readable post with discussion | Comments for that post. Show approved history; allow writing according to native policy. |
| Readable product with Store active | Reviews for that product, native rating summary and stars where Woo enables them. |
| Closed discussion with approved history | Existing history remains readable if the content itself is accessible. Explain that new submissions are closed. |
| Home, ordinary page or archive | Hide the automatic Comments Dock button by default. If the admin enables Site feedback, show the shared site-wide collection instead. |

An explicitly attributed control may target eligible native page comments instead of site-wide feedback. A product/post card must bind its actual loop item ID; it must not accidentally inherit the containing Home page's context.

The shared Seam action must resolve target, public root count, label and accessibility state. Attribute names will be versioned and documented during implementation, not advertised as already supported by this proposal. The Dock, Bricks icon and any custom attributed icon must receive the same behavior without page-specific JavaScript. Hiding the Dock button is presentation only; it must not disable a deliberately placed attributed control or erase data.

Count **approved top-level comments/reviews** in the public badge. Show reply counts within each thread. This avoids inflating a product's review count with replies and follows Woo's native root-review model. A badge showing the number of comments is different from unread notifications: opening the sheet does not make existing comments cease to exist. Suppress a zero bubble, but retain an eligible empty composer action.

The public screen uses paginated root cards, replies on expansion and a composer at the bottom inside the sheet. Preserve native threading/depth policy, approved-ancestor visibility and permissions. Present deeper native threads compactly without rewriting their parentage. Replies do not create product star ratings or change the product's average.

The Comments sheet owns the composer, conditional name/contact fields and inline OTP step; it reuses PhoneKey validation, contact proof, recovery and delivery rather than opening a second mandatory sign-in sheet. Preserve the draft, rating, target, parent thread, focus and underlying page position. Drafts are private to the active device/session, cleared after successful submission or sign-out and not mixed into shared page caches. Before Send, authentication never posts a draft. After explicit Send, a valid purpose-bound OTP may complete only that exact pending submission, once, without a second Send. Bind target, parent, content/rating and participant claim to the pending action; edits require renewed submission intent. Skip and Verify must share idempotency and concurrency guards. Back/cancel/expired delivery retains the draft; no duplicate overlays, automatic scroll restoration or page-top navigation.

## Public identity and PhoneKey

Native Subscriber is already a WordPress user role; it is not a separate role below all users. Keep the native role and track verification/participation assurance independently. Staff capabilities remain unrelated to the number of profile fields completed.

Founder-directed visitor journey:

1. Read approved discussion immediately and compose inside **Join the conversation**, without an authentication detour before typing.
2. An authenticated subscriber with a safe public name sees the comment composer; one missing a safe chosen name sees comment plus Name. An unrecognized visitor sees comment plus Name and Email or phone, subject to the site's allowed identifier mode. Existing valid data is reused without exposing contact details publicly.
3. Send uses the existing authenticated assurance when sufficient. Otherwise request an OTP inline through PhoneKey for the supplied contact. Do not require first/last name, a separate Profile visit or full recovery/passkey setup for an ordinary commenter where the configured policy permits contact-only participation.
4. A successful email/phone OTP proves that contact and makes the ordinary participant at least Partial under the existing PhoneKey assurance model. Complete the same pending Send once. Partially and fully verified participants are eligible for immediate publication only when native moderation, anti-spam and product-review policy permit it.
5. Skip OTP queues an unverified submission for moderation where the site's native participation/security policy allows it; it cannot silently bypass verified-only or other stricter requirements. Show its pending state to the submitter without adding it to the public approved count.
6. The ordinary standalone PhoneKey entry changes from **Continue securely** to **Subscribe Now**, explaining that this creates the free site account. Existing-account sign-in and privileged setup still require their normal proof. The native Subscriber role, account ownership and marketing consent retain separate meanings.

Source confirmation: pk_security_completion() currently reports Partial after at least one email/phone proof, reserving full Verified for the configured recovery factors and passkey. Use the established assurance owner; do not create a second comments-specific verified flag or a new WordPress role called User. Current eligible sessions do not need an OTP for every comment.

Never publish an email address, email local part, login name, phone number or phone suffix as a fallback identity. Use a chosen public name or neutral alias. Existing content must use a safe public projection without destructively rewriting historical native author data. Default initials/avatars must not introduce an external email-hash lookup. Staff/team badges come from trusted server-side authority, not from someone typing Admin as their name.

The founder intentionally wants the PhoneKey journey even when native discussion is open to everyone. In that mode, anyone can start a one-step join without already having an account; this is not a promise of anonymous posting. When native login is required, the journey must satisfy that policy and any configured higher-assurance rule. Do not silently relax native login, required field, review, verified-buyer or security settings, and do not fabricate email addresses for phone-only participants.

### Someone uses another person's contact details

Keep an unverified contact claim distinct from a verified owner. A new provisional session may draft or submit held content, but must not gain existing orders/private data, a verified-buyer badge, confirmed subscriber status or external delivery to the claimed contact. Creating a passkey on that provisional device is not proof of owning its claimed email/phone.

An identifier saved by a sale or earlier signup is not proof by the present commenter. Looking up an existing unverified or verified account must not authenticate it, borrow its assurance/badges, change its profile/name or attach a submission to it as a proven author. Without current session/contact proof the submission remains an unverified claim. After successful proof, associate it through the native ownership/recovery rules. Use non-enumerating visitor responses; owner-only possible customer matches must be clearly labelled unverified.

Reuse PhoneKey's established ownership, session and recovery controls. The new comment route must not mark an account verified, transfer ownership, enroll credentials or authenticate an existing account merely because a supplied identifier matches it. Integrate only through the existing proof results and current actor/session. Test the new composer at that boundary; there is no independent passkey/recovery redesign or new ownership-transfer implementation in these batches. Record any actual blocking defect before expanding scope instead of speculatively rebuilding the tested engine.

Contact verification, purchase verification and marketing consent remain separate. A low-friction unverified signup is not a confirmed unique newsletter subscriber. External notifications require verified destinations, applicable preferences and working delivery gates. Where proof is required but no gate is available, retain the draft and explain the available next step rather than pretending verification succeeded.

## Native policy and moderation

Use native WordPress comments and the established native validation/moderation lifecycle. Preserve comments-open status, login requirements, duplicate/flood checks, disallowed content, comment approval and installed anti-spam integrations. Protect the same data regardless of whether submission comes from the new DSA, a native theme form or an enabled native REST route.

Store is an adapter over this shared model. Respect Woo's review-enabled, star-enabled/star-required and verified-owner-only settings, including real purchase verification for the authenticated/proven identity. Enforce native lifecycle and aggregate invalidation rather than relying on a raw comment insertion. Some Woo validation hooks expect form variables/frontend context; a JSON route is not automatically equivalent. Test explicit native-policy adapters against real Woo forms and endpoints.

Root comments/reviews, ordinary replies and the optional site-feedback collection must be explicitly admitted types. Never expose order notes, webhook/system logs, private comments, hidden parent content or another user's held text. The same checks apply to lists, counts, notifications, author projections and search/deep links. A participant may see their own correctly bound pending submission; that response is private.

First-time unverified or OTP-skipped participation must be held for moderation. One contact proof makes an ordinary participant eligible for automatic publication; it does not override explicit approval requirements, spam admission, discussion closure or Woo verified-owner restrictions. Owner rejection/spam/trash must not be undone by a later OTP callback. Add baseline rate limits, same-site action protection, duplicate/idempotency checks and abuse admission **before enabling any public write route**, not in a later polish batch. Progressive challenges/verification may be used for risk; do not require a challenge or AI call on every page view or comment.

Reuse existing SecureTrack/abuse signals where relevant. Combine account/device/action limits, native flood controls, link/repetition checks and lightweight traps. PhoneKey alone cannot eliminate spam; bots can create accounts. Preserve existing anti-spam plugins until an independently qualified replacement exists. Historic spam review is a bounded preview workflow, not automatic mass deletion.

Existing approved comments, reviews, ratings and parent relationships remain native. Public-name cleanup must be deliberate and non-destructive. Own-comment editing/deletion, review edits and pending-to-approved transitions need permission, stale-write and native rating/cache tests before exposing such controls.

## Reactions, team participation and ordering

- Ordinary participants get one reversible Like per eligible comment; prevent duplicate/replayed votes and self-vote inflation. Participation assurance and abuse limits must prevent disposable accounts from determining ranking.
- A site-team heart is a distinct endorsement, visibly attributed to the business. A normal like from a staff member must not silently become a business endorsement.
- Recommend Top and Latest views. Team-hearted threads may be promoted in Top; Latest stays chronological. Pinning is an explicit moderator action with a bounded featured slot, not an unlimited automatic pin for every heart.
- Star score or positive sentiment must not determine whether genuine criticism is visible. Product reviews keep their native totals and rating average; likes, replies, hearts, site feedback and external testimonials never become product ratings.
- Reuse a suitable existing reaction owner if found during implementation. The current focused source review found no comment-reaction engine. If new storage is needed, use one bounded indexed relation with uniqueness per actor/comment, atomic transitions and derived counters. Do not keep unbounded serialized voter arrays in comment metadata or recalculate all voters during a page request.

## Optional site-wide feedback

Default off. Turning it on deliberately makes a shared Site feedback collection available on contexts without a selected post/product. It does not add a separate discussion to every landing page and does not mix with product reviews.

Choose and qualify one native WordPress-backed collection target in CF3. It must have explicit public visibility/submission policy, valid native identity and moderation behavior, and no orphan `post_id=0` records. Create storage/schema/target only during an authorized privileged setup or migration, never on a public GET. A hidden/private content target must not be made public by bypassing native permission checks.

Keep moderation impartial and identify the collection as feedback about the website/business. Do not promise Google review stars: Google's policy excludes self-serving Organization/LocalBusiness reviews from that review rich-result feature. Native eligible product rating/schema ownership remains with Store and the active SEO provider.

## Daily Feedback, settings and notifications

Extend **Kiwe > Daily > Feedback** as the one activity workspace. Newest activity first, stacked cards, meaningful context and native status, authorized moderation/reply/heart actions. Keep green for approved/published outcomes, yellow for work awaiting review and distinct spam/trash states; a low star score is not itself an error. Do not replace activity with analytics tiles or another frontend moderation application.

The founder specifies the private card reading order: safe commenter name; previous-purchase/customer context when Store applies; comment/review text and rating; the post/product/site target; assurance and moderation state/actions. Published comments by Partially or fully verified participants are green even if the account's separate Profile completion badge remains Partial. Pending submissions are yellow. Match purchase history only through proven native account/contact ownership; a possible unverified contact match is explicitly labelled and never grants a public verified-buyer badge. Purchase history remains owner/authorized-staff-only, with bounded native Woo lookups and no product/order work on non-Store sites.

The Daily badge represents unseen/actionable work, not all comments ever received. A moderation task remains outstanding until acted upon. An inbox notification should focus the relevant card/thread while leaving the rest of Feedback reachable without navigating away and back. These public-count, unread and pending-work numbers have separate meanings and must not share an ambiguous counter.

Policy settings have one editing home. Proposed shared discussion/site-feedback settings belong to the Feedback owner's settings panel; Dock/App settings control icon placement and screen presentation. Native product-only settings belong under App > Store > Reviews. Use existing native settings writers and preserve values when a presentation control is hidden. Exact layout is reviewed during CF4/CF6; no duplicate master switches in WordPress and Kiwe.

Reuse existing notification owners and bounded workers:

- Moderators receive new/held discussion events according to their capabilities.
- Participants receive approved replies, appropriate moderation outcomes and grouped reactions/team hearts. Do not notify an author about their own action or deliver unapproved text.
- Eligible Kiwe inbox notifications remain available by default; optional external delivery follows verified contact, topic preferences, consent and ready gates.
- Coalesce noisy likes, deduplicate retries/status transitions, avoid spam-triggered external mail and allow thread delivery preferences without turning public comment counts into unread counters.
- Product/site/deep-link destinations recheck current visibility and participant authority. No public notification payload may leak private contact or held content.

## Performance and ecosystem contract

The shared screen belongs to the existing app shell. Product behavior remains physically owned by Store and absent/inactive on a non-Woo installation. Base pages receive only the eligible current-target count/action projection; discussion lists, replies and the composer load on demand. No new site-wide comment polling, every-page AI request or full thread in each product card.

Use cached paginated approved lists, native count/aggregate owners, explicit invalidation and private responses for user-specific state. Batch visible identity/reaction projections and avoid per-comment order lookups or external avatar requests. Emit queued notifications after successful writes through the existing bounded worker, not synchronously inside the visitor's Send action. Under failure, retain drafts and show a clear retry state with idempotency protection.

CF4/CF8 must update Seam attribute/icon contracts, Bricks current-loop target bindings, compiler admission and Start's library/default post/product output guidance. Include capability/context/count descriptors in the existing SiteGraph/MCP authoring projection where applicable. This does not start the separately deferred MCP write or Google/Meta programmes. Until published and verified, new attributes/commands must be labeled proposed, not supported.

## Fixed batch ledger

**Six execution batches: five on the lab, then one consolidated publication/approved three-site rollout.** CF1 is complete. The five lab batches are accepted within the scopes and limits in [CF7](COMMENTS-CF7-RC451-2026-10-28.md); CF8 remains. The reduced CF2 is combined into CF3's execution batch rather than a standalone authentication programme. The original CF IDs remain stable and no requested comments/reviews, reaction, moderation, notification or ecosystem outcome is dropped.

| Remaining batch | Original IDs | Delivery |
| --- | --- | --- |
| 1 — Native comments/reviews foundation | CF2 + CF3 | Reuse PhoneKey and change its ordinary entry heading to Subscribe Now. Reuse safe profile/name and assurance data. Build native comments/reviews/replies/counts, pending versus verified submission, Store rating/purchase adapter, optional site-feedback target, baseline anti-abuse and duplicate protection. |
| 2 — Comment DSA and reusable controls | CF4 | Captivating post/product/site discussion screen, conditional name/contact fields, inline existing OTP/Skip, uninterrupted Send, preserved drafts/scroll/focus, Dock and attributed counts, Bricks loop targets and local Seam/Start contracts. |
| 3 — Reactions, team interaction and moderation | CF5 | Likes, business hearts and trusted team badges; Top/Latest and bounded pinning; progressive abuse admission and safe spam-review tools. |
| 4 — Daily Feedback and notifications | CF6 | Stacked activity with name/customer context/comment/target, green published verified participation and yellow pending, moderation/replies/hearts, full-stream deep links, admin/participant notifications and existing contact/audience visibility. |
| 5 — Complete lab qualification and founder review | CF7 | End-to-end subscriber/OTP/Skip/review journey, native restrictions, privacy, concurrency, accessibility, low-load behavior, Store on/off, preserved cart/checkout/data, exercised release recovery and complete UX review. Existing PhoneKey release checks plus targeted integration regressions; no standalone authentication requalification project. |
| 6 — Kiwe 1 publication and three-site rollout | CF8 | After complete lab acceptance and named live review, publish qualified shared Core/Store and Start/Seam contracts, preserve update compatibility and site policies, update all three sites, verify native owner/visitor flows and retire owned deployment inputs/jobs. |

| ID | Shared outcome | Completion gate |
| --- | --- | --- |
| CF1 | Automatic assigned-tag Search — **accepted448 and named rollout closed** | Text/tag union, deduplication, bounded continuation, visibility/cache invalidation and query-cost/Unicode tests. Independent small delivery is possible. |
| CF2 | Small PhoneKey reuse and public-identity contract — **combined with CF3** | Subscribe Now heading, existing role/assurance and safe public-name projection, shared OTP/Skip interface contract. No new authentication, signup policy, passkey/recovery engine or role migration. |
| CF3 | Native discussion/review service | Read/write/reply/counts, Store rating/purchase adapter, optional site-wide target, native entry-point parity, baseline abuse controls and idempotency. No public write without these gates. |
| CF4 | Contextual DSA and reusable controls | Conditional comment/name/contact composer and inline OTP/Skip, exact Send continuation, mobile/desktop threads, preserved scroll/focus, Dock/attributed counts, Bricks loop binding and draft contracts. |
| CF5 | Reactions and advanced abuse/moderation | Atomic likes, business hearts, bounded pin/Top ordering, progressive abuse handling and safe historic-spam review workflow. |
| CF6 | Daily Feedback and notifications | Role-specific stacked activity, customer/comment/target context, subscriber/contact-state visibility through existing owners, actionable badges, full-stream focused deep links, moderation/replies and deduplicated participant/admin delivery. |
| CF7 | Combined comment integration qualification and release recovery | Native compatibility, targeted reused-PhoneKey integration, privacy/concurrency/accessibility/performance, exact preservation and exercised upgrade/recovery. Lab review ready; no new authentication-engine test programme. |
| CF8 | Consolidated publication and named rollout | Reviewed Core/Store artifacts and shared Start/Seam contracts, named approval, actual guest/owner verification and complete owned-helper/input/job retirement. CF1 has a separate completed named rollout; later discussion rollout still needs named authority. |

Execution order: CF2+CF3 together, then CF4, CF5, CF6, CF7 and CF8. Keep all development on the lab and obtain complete founder UX acceptance before the named final rollout. Ordinary intermediate batches do not update production. Local native-service proof is distinct from an installed signed candidate, browser UX acceptance and final publication.

## Required qualification scenarios

- Post, product, explicitly targeted ordinary-page and site-wide contexts; Store on/off, Woo absent and another SEO provider active. Off/absent must leave no product engine requests or hooks.
- Open/closed, login-required, first-unverified, trusted/verified, role-restricted and verified-buyer-only policies; stars disabled/optional/required. Native guest email/name rules and phone-only accounts must be explicit.
- At the new comment-integration boundary: existing unverified Subscriber, newly captured contact, current Partial/full session, identifier matching another account, OTP success/invalid/expired/resend/unavailable, Skip and concurrent callbacks. Reuse existing PhoneKey admission; no borrowed account assurance, order exposure or external alerts to an unproven destination. Retain normal required release checks without adding a separate recovery/passkey development matrix.
- Private/draft/password-protected/deleted targets; spam/trash/order notes; held parents/replies; public-name projection, missing names and existing unsafe native fallbacks.
- Multiple tab/device writes, double taps, retries after timeout, stale moderation, repeat likes/unlikes, duplicate workers, rating changes and role/content visibility changes.
- Root/reply/rating counts and averages stay native; approved-to-spam/trash transitions invalidate all relevant projections. Notification counts and pending-work counts retain their separate semantics.
- Keyboard/focus/screen-reader operation, conditional name/contact fields, reduced motion, touch/scroll, safe areas, slow network and interrupted inline PhoneKey proof without shifting the underlying page. Bind Send/OTP/Skip to one exact submission, prevent duplicate/replayed/racing publication, and preserve a later moderator decision.
- Representative native and Bricks comment/review forms plus permitted REST paths; existing anti-spam lifecycle, settings, orders, stock, users, comments and authored data preserved.
- Record before/after cold/warm search and shell query/request cost, submission response time, list size, worker bounds and real guest cart/checkout responsiveness. A faster empty fixture is not proof of lower shared-host waits.
- Forward migration, disabled Store, partial failure and rollback retain native data and only the current run's recoverable inputs/jobs are retired. No automatic historical spam purge or website redesign.

## Primary references checked

- [WordPress native search implementation](https://developer.wordpress.org/reference/classes/wp_query/parse_search/) and [WP_Query search/taxonomy parameters](https://developer.wordpress.org/reference/classes/wp_query/).
- [Native comment submission validation](https://developer.wordpress.org/reference/functions/wp_handle_comment_submission/), [comment creation](https://developer.wordpress.org/reference/functions/wp_new_comment/), [admission/moderation](https://developer.wordpress.org/reference/functions/wp_allow_comment/) and [comment moderation guide](https://wordpress.org/documentation/article/comment-moderation/).
- [WordPress roles and capabilities](https://developer.wordpress.org/plugins/users/roles-and-capabilities/) and [REST comments](https://developer.wordpress.org/rest-api/reference/comments/).
- [WooCommerce product/review settings](https://woocommerce.com/document/configuring-woocommerce-settings/products/) and [WC_Comments source/reference](https://woocommerce.github.io/code-reference/files/woocommerce-includes-class-wc-comments.html).
- [Google review-snippet eligibility](https://developers.google.com/search/docs/appearance/structured-data/review-snippet) and [self-serving business-review policy](https://developers.google.com/search/blog/2019/09/making-review-rich-results-more-helpful).

These sources establish native behavior and constraints. Proposed Kiwe UX, risk policy, batching and storage decisions are architectural recommendations; they are not claims that the new feature exists or that the listed tests have passed.
