**Later resolution, 8 September:** The targeted RC55.2 SecureTrack correction is deployed; mainline RC70 remains local. [Native acceptance, recovery and deployment](SECURETRACK-DEPLOYMENT-2026-09-08.md). Historical investigation and local-only status below remain dated evidence.

# Ascendants installation-screen incident and development pause

8 September 2026. Initial incident status is preserved below. Update: the founder reports a 6 September backup restore and lost recent posts; fresh public requests now return 200. Recurrence prevention remains open; see [RC69 SecureTrack findings](SECURETRACK-SCHEMA-RC69-2026-09-08.md). The founder interrupted RC65–RC68 lab acceptance, reported that the Ascendants frontend shows WordPress installation, and asked to pause and document continuation. This new incident takes priority over ecosystem feature work. It is not a verified continuation or cause of the earlier publishing 503.

## Confirmed observations

At approximately 12:30 UTC / 18:00 IST, two bounded, unauthenticated GET requests produced different results:

- https://ascendants.in/ returned HTTP 200, an Ascendants title and a cache-hit header. The response read was capped at 128 KiB. The separate web retrieval also showed the normal homepage; neither establishes a healthy WordPress backend.
- The same homepage with a unique diagnostic query returned HTTP 302 to the same origin's /wp-admin/install.php. Redirects were not followed and the installer was not submitted.
- Hostinger's WordPress installation inventory identifies the existing Ascendants installation, ID 17159603, on account u406734797, but now reports is_valid=false. Its validation error from wp-cli plugin list says the requested site is not installed and suggests wp core install. That is diagnostic text, not an authorized recovery action; do not follow it by creating tables.

The uncached-looking request and installation validation support the founder's report. Existing cache can mask the failure. The specific cause and integrity of stored content remain unknown: no current database/table inventory, configuration comparison, document-root verification or recovery-point inspection was completed. Do not conclude that content was deleted, that Kiwe caused it or that a Hostinger outage caused it from these results.

## Exact stopping point

RC68 is a completed unsigned local candidate at canonical main, baseline revision 731a94ff48dd350bb7b168ec285fcc96afbdaa01, with existing changes uncommitted and unpushed. [RC68 scope and verification](WORKSPACE-ORGANIZATION-RC68-2026-09-08.md) remains valid: 59 grouped gates, 34 service syntax checks, 12 synthetic browser layouts and scoped behavior checks passed. Archive kiwe-rc68-lab.zip is 1,554,453 bytes, SHA-256 0164b1f8ed0161704b256bb0cab2b300a920e6507c9adcdf949067031861553f.

The lab is last verified as RC64; Ascendants is last verified as RC55. No current native Ascendants version could be established during this incident. Do not silently promote either last-known version to a fresh successful bootstrap.

The resumed lab attempt performed inventory reads and local document/script reads only. Hostinger WordPress inventory succeeded for the existing lab (installation ID 29833912, valid=true). The hosting server's first website-list call timed out after 300 seconds; its second outstanding read was terminated when the founder paused. Browser inventory was interrupted before a usable result. No upload credentials were requested, no archive uploaded, no package swap attempted, no cron job created and no hosting setting, PHP configuration, database, plugin or site content changed during this attempt. Nothing from RC65–RC68 reached Ascendants or the lab. There is no new temporary server job to clean up.

WordPress debug flags were disabled on all nine sites earlier as requested; they were not changed again. This incident did not read or recreate the old debug log. Production recovery requirements still apply; the lab's disposable-backup exception does not apply to Ascendants.

## Resume instructions

The founder resumed the investigation to identify the reported table owner. Prior pause instructions below are historical; [RC69](SECURETRACK-SCHEMA-RC69-2026-09-08.md) owns current priority. The next Ascendants investigation should be read-only first:

1. Compare its actual document root and active wp-config.php database selection, host and table prefix against the assigned existing database. Report only match/mismatch facts; never copy credentials into evidence.
2. Inspect that database's native WordPress table inventory and options-table presence/readability. Determine whether existing tables use a different prefix, configuration points to a different database, or a provider/database failure prevents recognition. Preserve all tables and existing records.
3. Review the relevant hosting change history and available production recovery points. Establish the correct source and exact proposed repair before any configuration rewrite or restore. A restore can discard newer articles, users and settings; its scope and recovery point must be concrete.
4. Do not run the WordPress installer, wp core install, replace the database, purge the working public cache, disable security or increase resource limits as an exploratory step. The installer prompt does not establish that a new installation is appropriate.
5. After a supported repair, verify a fresh WordPress bootstrap, native site identity and content counts, an authenticated admin session and uncached public routes. Revisit publishing only with a bounded request and native post-status readback. Keep the two incidents distinct unless evidence links them.
6. On resuming Kiwe work, start with one grouped RC65–RC68 native/session/browser acceptance pass on the lab, including the new Categories/Team handlers, then business-form convergence and existing native control links. Do not repeat the unchanged full local baseline or SEO phase. The RC56 native fixture is historical and needs a scoped current replacement before it can certify RC68.

Read-only evidence is stored outside source at C:/Users/shariq/Documents/Codex/2026-09-05/im/work/ascendants-install-evidence/public.json and incident.json; generated review copies live under that task's outputs/evidence. No credentials or page bodies are retained. [Earlier publishing incident](ASCENDANTS-PUBLISHING-INCIDENT-2026-09-08.md), [canonical handoff](../HANDOFF.md), [maintainer contract](MAINTAINER-CONTRACT.md).


## Restore and ownership findings, 8 September

The founder reports Hostinger identified repeated ALTER TABLE operations on wp_stp_events and a protection affecting only the Ascendants database. Kiwe SecureTrack owns that table. Its restored RC55 code retains automatic repair paths. The real WordPress schema parser reproduced 19 unnecessary events-table alterations per invocation against matching synthetic metadata. The specific server process/SQL trace remains unavailable, so this establishes a Kiwe defect and plausible incident contribution, not sole root cause. [RC69 correction](SECURETRACK-SCHEMA-RC69-2026-09-08.md) is local only. Fresh cached and cache-miss homepage GETs returned 200 at 16:39:44 UTC; the Hostinger inventory retained an invalid-installation result, so native/authenticated state still needs verification. No further site modification or post recovery was performed by this agent.
