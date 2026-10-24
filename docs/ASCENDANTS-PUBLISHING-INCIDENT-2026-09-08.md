**Later resolution, 8 September:** The targeted RC55.2 SecureTrack correction is deployed; mainline RC70 remains local. [Native acceptance, recovery and deployment](SECURETRACK-DEPLOYMENT-2026-09-08.md). Historical investigation and local-only status below remain dated evidence.

# Ascendants publishing incident — 8 September 2026

Status: investigating; a successful publishing request has not yet been observed. Source/package remains RC65, disposable lab RC64, Ascendants RC55. No Kiwe deployment, plugin deactivation, article publication or database restore was performed in this investigation.

## Founder report and immediate action

The client repeatedly encounters HTTP 503 in wp-admin. The founder narrowed the trigger to publishing a post, with the editor reporting “Publishing failed. The response is not a valid JSON response.” Whether the post actually reaches Published after that error is still awaiting confirmation. A failed response can occur after a database write, so inspect native post status before retrying an affected article.

The founder also explicitly requested disabling accidentally enabled WordPress debugging on all websites. Native wp-config constants were changed with WP-CLI before WordPress bootstrap, then independently read back. All nine discovered WordPress installations have WP_DEBUG=false, WP_DEBUG_LOG=false and WP_DEBUG_DISPLAY=false. All nine configuration files passed server PHP syntax checks.

| Website | Previous WP_DEBUG / WP_DEBUG_LOG / WP_DEBUG_DISPLAY | Verified result |
| --- | --- | --- |
| ascendants.in | true / true / false | false / false / false |
| basicbyte.in | true / true / false | false / false / false |
| beige-toad-741875.hostingersite.com | false / absent / absent | false / false / false |
| dharwadpedha.com | false / absent / absent | false / false / false |
| evanyaorganics.com | true / true / false | false / false / false |
| koshrr.com | false / absent / absent | false / false / false |
| nationalchikki.com | false / true / false | false / false / false |
| peachpuff-bear-428833.hostingersite.com | false / absent / absent | false / false / false |
| plum-giraffe-539524.hostingersite.com | false / absent / absent | false / false / false |

Existing log files were not deleted. PHP/server fatal-error logging is a separate hosting control and remains available. Do not turn WordPress debug logging back on as a routine deployment or incident step. The founder asked to stop pursuing the existing debug log; do not repeat that large-file investigation.

## Evidence and limits

- Two bounded homepage reads at 2026-09-07T19:00:19.489Z returned HTTP 200: Ascendants about 4.6 seconds, lab about 1.0 second. They do not test authentication, publishing, database writes or uncached editor requests.
- At 2026-09-07T20:02:47.700Z, public OPTIONS /wp-json/wp/v2/posts and GET of one post's id/status both returned HTTP 200 and valid JSON, in about 2.1 and 1.5 seconds respectively. The REST posts route is reachable; this still does not reproduce the authenticated publishing failure.
- Ascendants reports WordPress 7.1, PHP 8.2 and Bricks 2.3.12. Its installed Kiwe loader is RC55; later local RC57–RC65 behavior cannot explain the installed site's failure without separate evidence.
- The shared account has nine WordPress installations. hPanel showed 2 GB RAM and 40 PHP workers. Observed averages and one live snapshot are not fault counters or a correlation with a failed publish. Do not infer that buying a larger plan is the fix.
- Ascendants PHP memory_limit is 1024M and max_execution_time is 360. Do not raise them blindly on a shared 2 GB account. Disk and inode capacity were below their limits at inspection.
- The Hostinger upload-credential API repeatedly returns HTTP 500, while inventory, file reads and cron APIs generally work. A later transient connection reset/DNS failure recovered. RC65 never reached the lab; no package swap occurred. This API failure and Ascendants' 503 are separate observations until a common cause is established.
- hPanel itself showed a network-error toast during the resource-history inspection. Public scheduled-maintenance notices do not identify this account's server; do not attribute the incident to them without that match.
- SecureTrack's deployed post audit is one wp_after_insert_post callback. Its AI work is queued, not called inline. The wider audit still includes database work and conditional alert delivery; that does not prove its request cost is small.
- Installed Instant Indexing 1.1.22 attaches Google/IndexNow delivery to save_post when configured. Google submission executes synchronously and has no enclosing Throwable guard in that method. This is a candidate failure path, not a confirmed cause. Its vendor/autoload.php exists: the Hostinger directory-list response omitted that directory, so absence from that response must not be treated as an incomplete plugin installation.
- Native reads found no Google key or selected Google post types in either the current Instant Indexing option or its legacy option. The current IndexNow post-type field was absent, so its plugin defaults still need consideration. The suspected Google publishing path is not configured; do not blame or disable it based on source alone.
- A read-only native WP-CLI bootstrap returned blog_public=1 and no last PHP error. Earlier shell-loop option reads produced no field values and are inconclusive, not evidence that automatic indexing is off.

## Next acceptance gate

1. Obtain the affected post's native status after the error and a bounded failed-request record: route without credentials, timestamp/timezone, duration, HTTP status, content type and a redacted response summary. Keep article bodies, cookies, nonces and authorization headers out of handoff files.
2. Continue with the actual enabled publishing callbacks and the failing request; the Google indexing configuration check is complete. Inspect the actual installed callback before proposing a scoped repair. Do not disable security, caching or all plugins indiscriminately.
3. Correlate that request with Hostinger resource faults and web-server/PHP fatal evidence for that time. Do not recreate the accidental WordPress debug log.
4. Reproduce on the disposable lab where possible, then apply and verify the smallest supported repair. A homepage 200 or CLI exit zero does not close the publishing incident.
5. Resume RC65 native acceptance when upload access works, then Navigation/Design library reactive tasks. The UI sequence and deferred SEO work are unchanged.

The browser connector exposes the in-app browser; the previously available signed-in Chrome tabs are no longer available to the agent. A fresh Hostinger auto-login attempt reached Kiwe's Secure admin access enrollment gate rather than wp-admin. No recovery email was sent, new passkey enrolled or authentication policy weakened. Preserve that access limitation rather than claiming client-role browser acceptance.

## Operational reference

[WP-CLI config set](https://developer.wordpress.org/cli/commands/config/set/) and [config list](https://developer.wordpress.org/cli/commands/config/list/) run before WordPress loads. Use raw boolean false and restrict output to the three named debug constants; never export the full credential-bearing configuration. Temporary cron commands require an absolute WP-CLI path, are limited to 255 characters after escaping, and must be removed after completion. Verify receipt content and account job cleanup, not merely an accepted create/delete response.

[Hostinger's 503 guidance](https://www.hostinger.com/support/3417446-how-to-fix-the-503-error-at-hostinger/) and [resource usage guidance](https://www.hostinger.com/support/2436138-how-to-check-resource-usage-in-hostinger/) support correlating the failing request with hosting evidence. These general documents do not identify this incident's cause.

All temporary account cron jobs were removed after the bounded reads; the final native account listing is empty. The investigation leaves no scheduled diagnostic running.

## Reusable low-cost check

Run `node tools/maintenance/check-wordpress-rest.mjs https://ascendants.in/` from the canonical repository only when a fresh availability check is needed. It makes two sequential public GET/OPTIONS requests, limits response consumption to 128 KiB per request with a 15-second deadline, rejects credential-bearing input URLs, and reports status/content type/JSON classification/timing without response bodies or cookies. It never publishes, logs in, follows redirects or changes site configuration. A pass describes those two reads only. The first run passed both checks; authenticated publishing remains pending.

Runtime package files, architecture and product permissions are unchanged by this operational tool and configuration action, so no new Kiwe package version or full runtime baseline is required. Documentation links and tool syntax are the relevant local checks. Deployment, browser publishing and root-cause verification are explicitly outstanding.

## Later founder direction — development resumed

The founder suspects a Hostinger outage and asks to continue development. This is not a verified cause or a successful publishing retest. Investigation and hosting retries are paused; [RC66](WORKSPACE-TASKS-RC66-2026-09-08.md) proceeds locally. No debug logging is re-enabled and no hosting change is made by that build. Prior evidence remains historical and valid within its stated limits.


## New separate incident — 8 September, about 18:00 IST

The founder paused development after Ascendants displayed WordPress installation. A fresh homepage query redirects to install.php, and Hostinger reports the existing installation invalid. [Current incident and continuation contract](ASCENDANTS-INSTALL-SCREEN-2026-09-08.md) owns this investigation. No cause or connection to the earlier 503 is established.
