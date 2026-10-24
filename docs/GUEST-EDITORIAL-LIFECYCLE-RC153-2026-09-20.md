# RC153 Guest editorial lifecycle and default client app

RC153 was accepted on the disposable Hostinger lab on 20 September 2026. It completes the supported Guest application and article-publication path inside the Kiwe client app, makes that React app the normal client backend, and makes atomic updates refresh shared-host PHP opcode state. Ascendants remains recorded at RC55.4 and was not upgraded.

## Guest journey

A verified member can submit one pending Guest application. A client Administrator reviews the exact application in Kiwe, and approval changes the native WordPress role to Contributor only after readback confirms the new role. If that readback fails, Kiwe restores both the previous role and the pending application; an unconfirmed rollback is reported rather than presented as success.

An approved Guest writes through the bounded Kiwe article form. The submission becomes an author-owned native WordPress post, but the Guest cannot use the default WordPress editor or media library and cannot overwrite the post through the native REST controller. An Editor opens the exact Kiwe review task, assigns native categories and publishes through WordPress. The Guest then sees the publication state and public link in a read-only own-submissions view. Trash and other terminal records are excluded from exact links.

Application, approval and editorial notifications now link to the exact private Kiwe task and record. Publication links point to the public permalink. Events validate the post type, author and current status before a link is emitted.

## Client application and release reliability

The React workspace and client shell now default on when a host has not defined either flag. A recovery operator can still define `KIWE_REACT_WORKSPACE` or `KIWE_CLIENT_SHELL` as `false` before Kiwe loads to use the native fallback. The Guest document also has an exact `admin_init` bootstrap fallback because authenticated browser acceptance showed that WordPress can assign the fallback menu a screen suffix that does not run the expected load hook.

RC152's first atomic lab swap exposed a shared-host opcode condition: the new deterministic archive retained source timestamps while a web worker still held an older version constant. Package integrity correctly failed closed instead of running mixed code. RC153 now touches every installed package PHP file and invalidates each available opcode entry after activation and rollback. The same behavior is part of the in-product updater, not only the lab deployment helper.

## Verification

All 85 local green-baseline gates pass. The focused workflow suite passes 68 checks, Guest notification contracts pass 21, product-notification compatibility passes 19 with WooCommerce and 17 without it, and update recovery passes 26 checks across 10 cases.

The real WordPress lab passed all 19 final checks. They verify RC153 and its complete 424-file manifest, default React flags, debugging off, the client role boundary without `manage_options`, all private Guest routes, application and exact deep link, confirmed native role approval, Guest create-only access, an author-owned native submission, denial through the native WordPress REST writer, exact Editor review, native category and publication readback, Guest read-only publication status, Guest edit denial, anonymous denial and complete fixture cleanup.

Authenticated browser inspection opened both Home and Guests as clean Kiwe React documents without the WordPress sidebar. The Guest document showed application, review and contribution tasks; the client Home showed the complete role-derived task catalog. The Ascendants Glass Door was reopened separately and no production files or settings were changed.

## Deployment and recovery

The accepted archive is `kiwe-8.0.0-rc.153.zip`: 427 entries, 1,793,454 bytes, SHA-256 `25f3b841b23afd909bc222fcb283fb041fe4ffca3a9910d0e4e05dfffed065d7`. The lab verifies all 424 manifest files before enabling the package. RC152 remains in the account-private recovery area.

The final native receipt was recorded at `2026-09-20T14:45:03+00:00`. Synthetic accounts, category and article were removed. Public archives, deployment helpers and receipts were retired. The one intentional WordPress server cron runner remains, and WordPress debugging is off.

Machine-readable [RC153 acceptance receipt](generated/guest-editorial-lifecycle-rc153-2026-09-20.json). After this accepted package, 2–3 non-WooCommerce backend batches and 6–8 WooCommerce backend batches remain, for 8–11 total backend batches.
