# Lab client ownership and real dashboard acceptance

10 September 2026. Configuration and browser acceptance on the disposable Hostinger lab; installed Kiwe remains RC82. Ascendants remains the separately verified RC55.4 deployment. No runtime change, signed release, commit or push belongs to this configuration pass.

## Authorized account mapping

The founder explicitly chose to keep the usual setup account as Super Admin and asked us to create temporary client Administrator credentials and leave the client dashboard open. The existing Users → Access & ownership form activated the policy for the normally authenticated setup account. The owner reauthenticated normally after activation; the ownership screen verified the expected owner and active policy at 06:52 UTC. The setup account retains technical access.

A temporary account named **Kiwe Client Demo**, native user ID 78, was created with WordPress's wp_insert_user as the verified site owner. Its role is Administrator, and the native workspace predicate is true; that predicate is false for the owner. This account is deliberately retained for the founder's review and carries the kiwe_lab_temporary_account marker founder-authorized-2026-09-10. Its email uses the reserved example.invalid domain; no mailbox, real email delivery or verified identity is claimed. Setup blocked outbound mail/HTTP in that short CLI process. No real customer account or content was repurposed.

Credentials are absent from source, exports and receipts. Temporary access is stored with Windows user-bound DPAPI encryption outside repositories, at C:/Users/shariq/.codex/private/kiwe-client-setup-20260910/client-access.dpapi.json. The isolated Chrome profile is C:/Users/shariq/.codex/private/kiwe-lab-client-20260910. Neither directory belongs in an export. Keep the account until the founder finishes review; retire only this marked account afterward, checking for newly authored content before deletion.

## What was verified

- Real Chrome password login redirected the client to /wp-admin/admin.php?page=kiwe-workspace. Direct /wp-admin/ visits also reach Kiwe Home.
- Home identifies the Administrator workspace and exposes New post. The existing native editor loaded with draft, publishing and category controls.
- A temporary categorized draft was written through the editor. Authenticated WordPress REST readback confirmed the title, body, draft status, Blog category and temporary client authorship. Post 2916 was trashed through the UI and permanently removed through its authenticated native endpoint; deleted=true was verified. No public post was published. This is draft persistence acceptance, not complete publishing/provider acceptance.
- Desktop Home and 390-pixel phone Home were inspected. The phone page had no horizontal overflow and showed the app navigation dock. The client Home was left open in a separate visible Chrome window at desktop size; it is the actual lab session, not the localhost sample preview.
- Native checks confirm client post creation/publication capabilities and deny technical settings/ownership. dsa_settings, pk_settings_v3 and stp_settings were unchanged across account creation; Key remains enabled and all debug flags remain off.

## Security and login scope

The lab's existing replace_wp_login=false and protect_wp_admin=false settings were retained. Native WordPress password login therefore remains available. Neither the owner nor the new client has completed strict Key enrollment in this pass; the four-step secure-setup notice remains visible. No passkey, phone binding, email verification or password-bound enrollment metadata was manufactured, and Key's enrollment gates were not disabled. This does not prove a Key-issued session, physical passkey, phone verification or provider delivery.

The first native login displayed a WordPress test-cookie error; a fresh no-cache/BYPASS login request delivered the test cookie, and ordinary login succeeded. This is an observation, not a confirmed CDN defect or a deployed cache fix. Computer-use native-pipe discovery remained unavailable; the bundled Playwright runtime controlled a separate Chrome session instead.

## Confirmed next batch: owner and commerce permissions

1. **WooCommerce compatibility for the explicit owner.** The native Add User dropdown omitted Administrator even though the owner has create_users, promote_users and technical capabilities. Runtime filter inspection identifies wc_modify_editable_roles followed by Site_Owner_Service::editable_roles. The installed Woo source checks literal membership in the administrator role; the explicit Kiwe owner uses kiwe_super_admin. Woo also checks that literal role in wc_modify_map_meta_cap when handling another Administrator. The missing dropdown is browser/native verified; user-management denials beyond that are source-backed and need their own native acceptance. Do not remove Woo's safeguards globally, add an extra owner role, grant broad client technical access or bypass owner protection. Account creation for this authorized setup used the existing native API; the UI integration itself is still unresolved.
2. **Client Administrator commerce operations.** Current client_capabilities/capabilities_for omits product capabilities, content_boundary only admits post/attachment for this role, and workspace menus follow those restrictions. Native readback confirms editProducts=false/createProducts=false for the new client, despite Woo being installed. Shop-manager proof from RC76 does not cover this client role. Define and implement the supported product operations across capabilities, native object/AJAX/REST boundaries and task navigation together, keeping technical Woo settings and unsupported order/payment operations scoped. The founder's client-management intent requires this gap to be closed; do not work around it by making the client a Super Admin.

These are runtime changes for a new immutable candidate with meaningful owner/client denial tests and one combined native/browser acceptance pass. Continue full-bootstrap/security-event profiling and the remaining first-launch journeys after this integration batch. Do not describe this setup as complete client product management.

## Cleanup and evidence

The setup PHP ran once behind a CLI guard, exact lab path/version/owner checks, a private directory rule and a one-shot lock. It removed itself after verification; Hosting file readback confirms it is absent. The local plaintext setup script and credential staging file were removed. The temporary cron job was deleted and the account cron list is empty. The protected sanitized setup receipt and lock remain; no executable setup endpoint remains. No full lab backup was taken.

Evidence is outside source under work/client-setup-evidence: ownership.json, native.json, browser.json, cleanup.json, home-desktop.png and home-phone.png. The [production backport report](RETENTION-DEPLOYMENT-RC554-2026-09-10.md) owns the separate 52 local / 19 native checks and production deployment/cleanup. The [first-launch plan](FIRST-LAUNCH-PLAN.md) remains broader than this pass.
