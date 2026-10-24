# RC60 — Owner permissions and client AI choices

6 September 2026. Local source `8.0.0-rc.60`; disposable Hostinger lab remains RC58. No deployment, signing, Git commit/push, provider call, key change, billing change or customer message in this batch. Ascendants is unchanged. This follows [RC59 request allowance](AI-ALLOWANCE-RC59-2026-09-06.md) and implements the founder's owner-permission/client-choice requirement in [AI affordability and SEO](AI-AFFORDABILITY-SEO.md).

## Result and editing homes

The technical owner permits SEO independently in the existing AI setup. Previously SEO inherited SiteGraph enrichment permission. SecureTrack retains its existing AI bridge permission, with explanatory text linking it to broker reviews. No duplicate API key, provider setting, request ceiling or SecureTrack switch is introduced.

Client Administrators have one **AI assistance** destination in the menu and Home task cards. Two responsive cards control website-wide SEO and SecureTrack preferences. They display owner availability and the client's choice; an on preference is not a claim that a provider is configured or a review is running. A separate read-only card shows shared minute/day/month request usage and a provider pause when present. It does not expose credentials, URLs, provider diagnostics, prompts, budget editing or resets. The global pause reads the original `ai.allow_native_generation` field. Enabling a preference never dispatches work or changes an existing feature schedule; a previously scheduled consumer may use it on its next request.

The technical owner uses that same destination to view or change client preferences. AI setup links there instead of duplicating the controls. Authors, Editors, Contributors, Subscribers and Shop Managers do not gain site preference authority. Future per-object SEO actions will have separate object/capability checks; this batch does not grant them or add their buttons.

## Authority and compatibility

| Concept | Native authority | Writer / enforcement |
| --- | --- | --- |
| SEO owner permission | `dsa_settings.ai.seo_enabled`, false by default | Existing owner-only AI form and sanitizer; unrelated partial AI saves preserve it |
| SecureTrack owner permission | Existing `dsa_settings.ai.securetrack_brief_enabled` | Existing owner-only control; existing redacted Companion export scope remains separate |
| SEO client preference | `kiwe_ai_opt_in_seo_v1` site option | AI assistance, `kiwe_manage_ai_preferences`, nonce-protected POST |
| SecureTrack client preference | `kiwe_ai_opt_in_securetrack_v1` site option | Same editing home and capability, separate feature-bound nonce |
| Request limits / consumption | RC59 native AI settings and allowance ledger | Existing owner control and transport reservation; the client can only read aggregate usage |

The two new preferences are Kiwe inventions, stored through `update_option` with autoload false and native readback. Each submission writes exactly one allowlisted option, so simultaneous changes to different features cannot overwrite each other or a technical setting. Same-feature submissions use ordinary last-writer semantics. Unknown feature IDs, arrays, malformed boolean values, missing/wrong nonces, wrong methods and denied roles cannot mutate state. Read-only visits do not create preference or usage options.

Broker profile filtering is followed by the built-in intersection of owner permission and client preference. Filters can narrow access but cannot re-enable these two features against the intersection. A request rejected here never reaches rate accounting or provider transport. Existing global setup, feature-specific operating controls, prompt/capability checks and the shared allowance still apply. An already dispatched request can finish after a preference changes; this is not cancellation of in-flight provider work. Owner settings follow the existing request-scoped Settings snapshot; revocation applies to subsequent web/worker requests, not retroactive cancellation.

**Upgrade behavior:** both new client preferences start off on fresh and existing sites. SEO also needs its new explicit owner permission; an older SiteGraph switch is not migrated into consent. Existing SEO data, ordinary security enforcement, deterministic SEO and other native settings are preserved. Existing SEO jobs may pause on their next disabled request and keep their existing resume controls. Review both choices during rollout. Owner revocation overrides a saved on preference; restoring permission makes that remembered choice eligible again. A client may turn the preference off while owner permission is revoked.

Studio, SiteGraph, Design Context and Bricks technical workflows retain their current permission contracts. Redacted SecureTrack Companion exports retain their existing owner permission and API scope checks; the client preference gates Kiwe provider inference, not external accounts or previously issued credentials. Future client features must declare their own explicit policy adapter. This is not arbitrary plugin sandboxing or a monetary cap.

## Implementation and acceptance

Runtime changes are limited to Kiwe's AI policy/broker, owner settings/sanitizer, client role/menu/route/Home composition, new AI workspace, the SEO setup message and release metadata. The workspace reuses the existing task stylesheet only on its own screen; no public script, new stylesheet, custom table, queue, daemon or cron is added. Key and Seam need no changes because their contracts and external authorities are unchanged.

The new deterministic fixture executes the real broker, preference policy, role boundary, POST handler, render, owner sanitizer and allowance reader with synthetic WordPress/provider I/O. It covers permission intersections, denied and forged actions, native state projection, unrelated-setting preservation, failed storage and disabled zero-call behavior. Transport success in this fixture means a stub was invoked, not live inference. The grouped local result is recorded below after execution.

Pending grouped lab acceptance: real WordPress options and owner/client forms, restricted-role direct routes, phone/tablet/desktop and keyboard behavior, owner revocation across worker requests, and RC59 MySQL reservation/cooldown proof. No provider spending is necessary for the native permission checks. Preserve lab owner policy and AI settings after fixtures; no routine full SQL/ZIP backup is required on the disposable lab.

Recovery: revert the complete matching loader/package pair. RC59 ignores the new preferences and uses its earlier broader AI gates, so disable native generation through the existing owner control before rollback if consent enforcement must remain intact. Preference rows can remain inert; do not silently delete consent state during upgrades. Existing request allowance must not be reset.

Next: conservative token/cost reservation and bounded per-object/resumable SEO, then combined lab acceptance for the related AI foundation changes. Do not market automatic free operation, guaranteed monthly bills or search rankings from this batch.

## Recorded local result

- Canonical Kiwe remains on main at 731a94ff48dd350bb7b168ec285fcc96afbdaa01, with earlier dirty work preserved. RC60 source is uncommitted/unpushed; no archive, signature or deployment was made.
- Node 22.23.1 and PHP 8.4.23 on Windows. The first grouped baseline passed all 55 existing gates and failed the new permission gate because SEO still read SiteGraph's flag. That one-line runtime wiring was corrected. The first focused rerun then exposed missing Settings initialization in the test's reflected Admin instance; the fixture was corrected and Shop Manager denial added.
- Final focused result: all **52 permission behavior checks** and **18 existing broker contracts** passed. The changed broker passed PHP lint and the regenerated **367-file RC60 package** passed verification. The initial grouped **25 service lints** passed; only the subsequently changed broker needed repeat lint. Other unchanged baseline gates were not repeated. These are source/synthetic checks, not native/browser acceptance.
- Initial baseline receipt: baseline-1788688036998-8e29f64f-0f33-4d53-b077-eafe08414a73.json. Initial syntax receipt: syntax-1788688007312-17deee5c-1dd0-4610-a0d9-bdb0bda1c797.json. Both bind source hash c0cf5460b50ffa9b21d87b6f007d7521056a3dbdf9f8246b20af64657b0c688f. Retain the failed baseline as historical evidence.
- Corrected permission/broker/package receipt: features-1788688129693-9c467566-7de3-423b-8b76-a4981102f1b7.json, source hash bbf6c10579d576b83f4dd140b04e1440ae5873fac81e7588986b6676672376cc. Runtime and package bytes did not change after this receipt; only documentation/export notes were completed. Receipts reside in the task's external work/reserve-evidence directory and are copied to outputs/evidence.
- Repeatable focused command: node tools/maintenance/run-reserve-task.mjs features --out <absolute external evidence directory>. This checks permissions, broker contracts, broker lint and package verification. The baseline also includes the new permission gate. Run named recipes only after relevant changes, not on every continue.
