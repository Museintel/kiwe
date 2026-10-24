**Current completion status, 21 September:** [RC157 provider readiness and reversible SEO migration acceptance](AI-SEO-PROVIDER-MIGRATION-RC157-2026-09-21.md) closes the final AI/SEO backend implementation package. Provider status is credential-blind and zero-call on read; one explicit owner confirmation uses the shared allowance and saves only a sanitized receipt. Supported Rank Math metadata imports only into empty Kiwe fields in bounded batches, preserves the source and exact rollback, and never spends AI allowance. Lab evidence passed; real-provider quota and production-record evidence remain rollout checks. Ascendants is restored to RC55.4 and its deployment needs separate approval.

See [the ecosystem ledger](ECOSYSTEM-STATUS-2026-09-12.md) for completed workflows, partial subsystems and future products. This document retains its product contracts and dated evidence; old release labels below do not override the current note.

# AI affordability and SEO delivery

**27 September RC205 implementation:** Object and image SEO now share a manual form and bounded Kiwe AI proposal action. A retry sends only sanitized previous wording to request a materially different alternative, without a native write; Save uses source fingerprints and readback. Rank Math post/term metadata remains authoritative when active; category archives use term metadata, while menu groups have no SEO archive. Assistant media filtering is bounded to native attachment parent, featured image and Woo gallery relationships; arbitrary references embedded in post content are not inferred. See [RC205 evidence and limits](SEO-EDITOR-WORKFLOW-RC205-2026-09-27.md). The older RC64 notes below are historical.

**Current source and lab: RC64.** [Grouped native/browser acceptance](RC64-LAB-ACCEPTANCE-2026-09-07.md) is complete: RC59–RC63 foundation checks and an eight-process race passed; RC64 fixes the native checklist form rendering blocker. Next is shared DSA/client-dashboard primitives, navigation and one complete native-data task. SEO features are paused. Source remains uncommitted/unpushed; Ascendants unchanged.

**Historical batch notes below record their then-current deployment state; they do not override RC64 above.**

**Current local implementation:** [RC63 bounded checklist](SEO-CHECKLIST-RC63-2026-09-06.md) completes this phase's SEO feature work above RC59–RC62 request/token/permission/job foundations. One combined native acceptance pass follows, then shared DSA/client-dashboard UX. Object-level SEO actions, deeper historical automation and monetary controls remain deferred. No live provider or money-cap claim is made. The RC58 inventory below is historical.

Updated 6 September 2026. Status: founder product direction and implementation proposal; the source inventory below describes RC58, not provider acceptance. RC58 has received scoped lab acceptance; RC59–RC63 are subsequent local AI foundation builds.

## Standard client setup

15 October planned external-AI path: an authorized AI client can submit scoped validated design/content/SEO/campaign proposals through the [new MCP programme](GOOGLE-MCP-DELIVERY-PLAN-2026-10-15.md) while Kiwe AI is absent or disabled. Native writers enforce permissions and stale-state checks; no hidden second generation through the site's Gemini broker. The external subscription does not fund Gemini API, ad spend or delivery, and does not grant Google/hosting access. Current public read/validation capsules remain unchanged until the new mutation boundary is implemented and accepted.

One Kiwe website has one dedicated Gemini project/API key and a client-controlled Google account. A business-managed account is recommended for continuity; normal Gmail works and Workspace is optional. Projects/keys are not pooled across customer sites. Within a website, SEO, SecureTrack and future AI consumers share that project's quota and Kiwe budget. The technical owner configures secrets, approved providers/models, limits and allowed consumers. The client Administrator sees useful controls, remaining allowance and progress, and may disable or enable features only within that allowance. Other roles request object-specific assistance only where they can already edit that object. Never expose credentials through client controls or exports.

14 October clarification: the earlier business-mailbox recommendation was a commercial preference, not a Google technical requirement. Google sign-in does not provision API credits or authorize private Analytics data to enter unpaid Gemini. [The proposed Google journey](GOOGLE-ECOSYSTEM-PROPOSAL-2026-10-14.md) retains site-owned enrollment and compatible processing terms; it is not an implemented integration.

This is the intended commercial setup, not authorization to purchase or provision mail accounts, enable billing, or send messages. A business mailbox is not evidence of Gemini API credits or a paid API tier. Key.kiwe's WhatsApp multi-tenant control plane is a separate credential boundary.

## Provider facts and resulting design

Google documents Gemini limits per project, not per API key, with model/tier-specific request and token windows. Active limits should be checked in AI Studio; advertised capacity is not guaranteed. Kiwe must not hardcode a universal free allowance or rotate keys to evade quotas. Calls outside Kiwe against the same project can consume capacity its local ledger cannot observe. [Gemini rate limits](https://ai.google.dev/gemini-api/docs/rate-limits).

Eligible models offer a limited free standard API tier; free availability varies by model and feature. The proposed Kiwe queue groups ordinary API requests into small software batches. That does not mean using Google's separately priced Batch API for free. Stop or defer when free limits are reached; no silent paid fallback, model upgrade or billing activation. [Gemini pricing](https://ai.google.dev/gemini-api/docs/pricing).

Free-service data handling constrains payload design: minimize and redact before transmission; exclude credentials, personal/customer/order data and private material from unpaid-service packets. Public visibility alone is not proof that a packet contains no personal information. SecureTrack must send a bounded redacted classification packet rather than raw incidents, IP addresses or request bodies. Regional and paid-service terms differ; record applicable provider policy during setup. [Gemini API terms](https://ai.google.dev/gemini-api/terms).

## Existing source and the gaps

| Foundation in RC58 | Remaining work before the full promise |
| --- | --- |
| AI_Broker_Service isolates consumer prompts, validates declared capabilities/output, limits prompt bytes and calls per service/minute | One atomic shared per-site request/token/cost ledger; owner allowance separate from client opt-in; all transports and retries governed by the same reservation |
| AI_Provider_Service stores secrets through Secret_Store, gates native generation, bounds output and makes at most one retry for selected 5xx responses | Capture provider usage; charge every attempt conservatively, handle unknown usage without refunding possible spend, defer 429 by provider reset/retry guidance; rate limit is not a monthly spending cap |
| SEO_Refinement_Service proposes up to five records per call, keeps a cursor, pauses after errors, offers manual next batch and proposal review, checks source hashes before apply | Per-object role entry points, explicit pause/resume/cancel, atomic worker lease, paged discovery instead of loading every ID, bounded proposal storage, quota-aware scheduling and truthful completion percentages |
| SEO batches include posts/pages/products/media and resolved Design Context goals, audience and verified business evidence | Audit private/protected parent eligibility and publication/object permissions at discovery, processing and apply; media metadata is not visual understanding; don't invent alt text |
| SEO proposals write native media fields and Kiwe SEO metadata; existing dedicated SEO plugins retain frontend authority | Audited Rank Math migration, one active frontend emitter, mapping/rollback for titles/descriptions/robots/canonicals/social/schema/sitemaps, and preserved URLs; no automatic plugin deactivation now |

Source entry points: [broker](../wp-content/mu-plugins/dsa/includes/AI/AI_Broker_Service.php), [provider](../wp-content/mu-plugins/dsa/includes/AI/AI_Provider_Service.php), [SEO refinement](../wp-content/mu-plugins/dsa/includes/SEO/SEO_Refinement_Service.php), [SEO context](../wp-content/mu-plugins/dsa/includes/Onboarding/SEO_Context_Service.php). Presence of these controls is not proof of full permission, quota, privacy or provider acceptance.

## Required client journeys

An authorized author can request SEO when uploading eligible media or preparing a post, page or product. The action offers a bounded preview and applies only permitted fields to the same native object. Uploads, publishing and browsing remain usable when AI is off, paused or unavailable. Content is never silently sent just because an upload hook fires.

For an older library, the client selects a scope and starts a resumable job. Discover IDs in bounded pages, save a stable job boundary and source hashes, coalesce unchanged work, and cache by content/context/model/prompt version. Show inspected, eligible, proposed, applied, skipped and failed counts separately. A percentage of inspected records is not a percentage of SEO improvements applied. Quota-paused work shows its reason and next eligible attempt; a retry does not reprocess the whole library. No AI call waits inside the public visitor path.

Design Context supplies audience, search intent, evidence and the business goal: traffic, leads or sales when commerce is enabled. It does not authorize fabricated claims. Internal SEO checklist completion is separate from rankings, traffic and conversions; never promise a guaranteed search position.

## Next bounded AI build

The initial request allowance and SEO/SecureTrack consumer permissions now exist in local RC59/RC60. RC61 adds shared token reservation/settlement. RC62 supplies bounded resumable jobs. RC63 supplies bounded overall-score aggregation. Defer further object actions and provider-backed cost refinements until after the shared dashboard phase, preserving the permission intersection. Use integer budget units, an atomic reservation/settlement boundary and short worker leases appropriate for shared hosting. Free-only mode has no paid fallback; optional paid use requires an explicitly configured site ceiling and verified pricing. Retrying and concurrent consumers consume the same allowance. A local ceiling cannot guarantee an external billing total when outside callers share that project.

After the dashboard phase, connect object-scoped SEO entry points to the RC62 bounded worker and review buffer. Consolidate client controls into one SEO home with links from native editors. Validate these changes in one grouped pass: concurrent reservations, denied roles, stale content, protected media, provider 429/5xx/timeouts, restart recovery, disabled zero-call behavior and accurate progress. Provider mocks establish deterministic boundaries; one explicitly configured lab provider check establishes transport behavior. No API calls, key changes or billing changes were made for this documentation pass.
