# Separate technical AI permission from client preference

Date: 6 September 2026. Status: accepted in local RC60 source; lab acceptance pending.

The founder wants the owner to configure one website's AI project and permit consumers while the client chooses where assistance is used. Giving a client the broad settings form would expose technical authority. Reusing SiteGraph's flag for SEO would couple unrelated choices.

The existing owner settings remain authoritative. Add `ai.seo_enabled`; retain SecureTrack's existing bridge permission. Two Kiwe-owned, non-autoloaded native site options hold independent SEO/SecureTrack client preferences. Both default off, including on upgrade. One capability and one client editing home accept a feature-bound nonce and a strict on/off value, write only the declared option and confirm through native readback. There is no shadow copy of provider settings, request ceilings or native SEO/security data.

The broker intersects built-in permission and preference after extension filters, before rate accounting or transport. Existing actor/object permission checks in consumer entry points remain necessary; a website preference does not authorize an individual to edit or publish content. Revoking owner permission overrides remembered client consent; restoring permission may make it eligible again. In-flight requests and the existing request-scoped Settings cache are not cancelled. Redacted Companion exports have a separate scope contract.

Alternatives rejected: a client-editable copy of `dsa_settings` could overwrite unrelated settings or elevate authority; one read-modify-write preference map would require extra concurrency machinery to avoid cross-feature lost updates; granting SEO through SiteGraph would hide its actual permission boundary. Two bounded native options are simpler, with ordinary last-writer semantics only for the same feature.

The scope adds no external API, cron, public asset or dependency. Other technical AI consumers keep existing contracts until an explicit client-facing adapter is designed. Keys and billing remain outside client control. Rollback requires pausing native generation if the older broader gates would violate the desired consent policy.

See [RC60 implementation and acceptance](../AI-FEATURE-PERMISSIONS-RC60-2026-09-06.md) for checks, rollout limits and recovery. Key and Seam are unaffected.
