# Bounded checklist generations and atomic snapshot publication

6 September 2026. Accepted for local RC63; native lab acceptance pending.

Problem: a routine score update loaded the full content/media catalogue and legacy proposal state. The user wants cheap, responsive shared-host administration and has asked to finish this SEO phase.

Decision: read at most 25 compact native records per ordered-ID step. Persist only counters and one completed report in a Kiwe-owned, non-autoloaded JSON option limited to 16 KiB. A 60-second lease and byte-exact CAS fence worker completion. Requested/completed generation tokens retain invalidations that arrive during a scan. Publication and completion are one write. The existing report shape remains readable through the shared getter; the legacy option is a fallback, not a second writable authority.

A read-only score page can safely replay after a crashed lease, so recovery is automatic when cron executes. RC62 AI operations retain their separate manual recovery rule because replay can consume allowance. An incremental per-object score table would need old-contribution storage, migration and consistency handling for every external edit; one bounded rescan is simpler for this initial phase. A full SQL aggregate would avoid transferring rows but still put the entire calculation in one request. Neither alternative is adopted here.

The score measures native metadata presence, not AI approval or rankings. Private/protected records and unavailable Woo products do not participate. Changes may yield a mixed-time report marked pending until a follow-up scan; a frozen database snapshot is not claimed.

Data shape, compatibility/downgrade behavior, synthetic/native test distinction and recovery are maintained in the [RC63 contract](../SEO-CHECKLIST-RC63-2026-09-06.md). After one combined native acceptance pass, shared DSA/client-dashboard UX becomes the next product focus. No Key/Seam, provider or monetary control change is part of this decision.
