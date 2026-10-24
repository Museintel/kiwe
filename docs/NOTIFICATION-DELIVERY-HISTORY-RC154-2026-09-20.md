# RC154 notification delivery history

**Accepted:** 20 September 2026  
**Installed target:** disposable Hostinger lab  
**Production boundary:** Ascendants remains on its recorded RC55.4 runtime

## Result

RC154 adds a bounded, recipient-owned notification history to the native Kiwe client app. A permitted staff account can open **History** to review the newest 40 inbox records in two pages of 20, see read state and understand whether each selected channel accepted, partly accepted, used a fallback, failed or became unavailable.

Opening Notifications or its Inbox performs no provider send and does not load history. History is fetched only after the user opens it. The implementation reuses the existing WordPress user-meta inbox and preference table; it adds no table, public route, polling loop, cron worker or second notification authority.

## Delivery semantics

- **Workspace · Saved** means Kiwe retained the event in the current recipient's bounded inbox.
- **Accepted** means the configured service accepted Kiwe's handoff. It does not claim mailbox or handset receipt.
- Browser push reports accepted device counts per WordPress user and distinguishes partial device failure.
- Email, WhatsApp and SMS preserve the existing credential-blind `Channel_Service` boundary.
- Provider refusal is projected as a generic failure. Recipient addresses, phone numbers, push endpoints and provider responses never enter history.
- An unavailable channel and a missing verified contact are visible as separate recipient-safe outcomes.
- Explicit acknowledgement uses a compare-and-swap update, so a concurrent new event cannot be overwritten. Read state is shared by the same WordPress account across devices.

## Authorization and limits

The private REST route is `GET /dsa/v1/workspace/notifications/history`. It requires the existing Notifications capability, a valid WordPress REST nonce and a same-site request. The route derives permitted topics from the current role on every read, clamps pages to 1–2 and returns private no-store responses. Another administrator cannot read a recipient's user-meta inbox, and anonymous requests are denied.

The 40-record cap is deliberate shared-host protection. RC154 is an operational history for current work, not an audit archive or delivery-provider ledger.

## Verification

All 85 local release gates pass. Focused coverage includes configured email acceptance, refusal, unavailable delivery, Woo and non-Woo topic behavior, two-page history, role filtering, output sanitation, compare-and-swap acknowledgement, source-to-asset parity and the 90-endpoint workspace inventory.

The disposable Hostinger lab passed 18 native WordPress checks with a temporary Editor account and the real preference table, notification services, REST server and WordPress email transport. Outbound mail was intercepted before transport. The pass proved:

- RC154 runtime and 424-file manifest integrity;
- role-derived editorial topic visibility;
- native per-topic preference persistence and audience synchronization;
- accepted, failed and disabled-channel history outcomes;
- recipient-safe output shape and private REST headers;
- persisted read state, another-account isolation and anonymous denial;
- exact removal of the temporary account and audience row;
- settings preservation and debug-off state.

The React source/asset behavior passed the automated app contract. The authenticated visual surface was not re-inspected after deployment because the browser-control channel became unavailable after the separate Ascendants handoff; the installed REST and service behavior was exercised natively.

## Release evidence

- Version: `8.0.0-rc.154`
- Production package manifest: 424 files
- Folder-copy archive: 427 files, 1,795,567 bytes
- SHA-256: `a24e49011a5f2688b1f935c57b7d07b3bc96730dc6168256bab053d8e31f3d78`
- Public lab homepage: HTTP 200 after activation
- Public lab manifest: RC154, 424 files
- Rollback: private RC153 recovery created by atomic activation
- Cleanup: temporary accounts, preferences, public helpers, archives and deployment jobs removed; the single intentional lab WordPress cron remains

## Ascendants access handoff

The actual SecureTrack Glass Door was opened at the standard WordPress username/password screen rather than the PhoneKey route. Its private lookup value is not recorded here. The temporary reader and cron were removed, and the Hostinger account returned to the single intentional lab WordPress cron. No Ascendants package or setting changed.

## Remaining notification work

Personal topics, per-event choices, site comment-email controls, bounded inbox/history and basic delivery outcomes are now accepted. The remaining backend notification package is a role-aware client campaign and audience workflow using the existing campaign service, with consent and configured WhatsApp/SMS disposition. It must not create another preference or recipient store.
