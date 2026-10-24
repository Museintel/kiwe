# Kiwe notification architecture

RC347 core contract: the native inbox and preference journey are always available. Legacy master pause flags are retained for recovery but do not disable event hooks, browser enrollment or security notice generation. Severity and flood-control rules remain authoritative; this does not activate analytics, tracking or extra security enforcement.

RC358: each account receives its role/event-eligible updates in Kiwe without a delivery subscription or inbox opt-out. `inboxTopics` is only a derived compatibility projection of the private role catalog; legacy input cannot disable inbox updates or broaden entitlement. Existing `topics`, `channels` and per-topic overrides govern optional delivery only. Browser/PWA delivery still requires explicit device permission; contact routes reuse Key.kiwe and Channel_Service verification. External publishing and marketing remain opt-in. [Decision and bounded acceptance](SAVED-CART-INBOX-RC358-2026-10-14.md).

The shared header counts only native new updates. Persistent website notices retain their own status areas and never inflate this badge. Foreground Notifications visits automatically acknowledge their captured native snapshot, keeping seen history and any later arrivals. Home cards independently track new records since each account last opened that space. The stable `ai` Dock module now presents Notifications, including Choose alerts and Refresh updates. Its private inbox is fetched on opening or explicit refresh, with throttled focus refresh only while open. It has no repeating background poll. Private notice bodies remain in WordPress; device-local guest notices use account-scoped session storage. A response from a previous account cannot populate a new session.

Recent missed Woo orders are recovered only when authorized staff open their inbox: at most ten orders from the last seven days, with a shared five-minute discovery cache. Recovery adds only native entries, preserves acknowledgements and never replays historical gateway sends. Source creation handles native Woo objects and ignores checkout drafts. Account reset/verification/role events and customer order status use the existing topic router. The existing publication cursor discovers eligible native notices through the audience index without duplicating channel sends. Its index is not external-consent authority. Core event candidate fanout remains bounded at100 accounts; existing staff discovery enumerates native staff roles plus the designated owner. This is not an unrestricted whole-site broadcast guarantee.

The inbox stays capped at 40 records per account and projects 20 per page. Current role, suspension, membership and object ownership are checked when opening or acknowledging notices. The private header reads only bounded existing metadata; it does not scan orders. Bricks AJAX uses the native global setting filter before Bricks loads its cache, preserving every unrelated setting and adding no per-request option write.

Kiwe has one notification preference and transport authority for every
subsystem. SecureTrack, editorial, Guest and staff events publish a topic and a
presentation-safe event to `kiwe_notification_event`. Recipient-specific Woo
order changes also use this shared router with an exact account recipient.
Audience fan-out workers retain their existing cursor/category handling and
reuse the same preference, Push and `Channel_Service` boundaries. Sources never
own SMTP, WhatsApp, SMS or push credentials.

## Delivery model

- The Kiwe inbox is the bounded in-product authority. Each account keeps its
  newest 40 items in WordPress user meta; this cap prevents unbounded tables or
  read work on shared hosting.
- Browser/app push is a device permission layered over the inbox.
- Email, WhatsApp and SMS are the three external delivery channels.
- `Channel_Service` is the credential-blind gateway boundary for all modules.
- Unconfigured gateways are disabled in both WordPress Notifications and the
  DSA Notification surface; Kiwe never accepts a channel that cannot deliver.
- Topic and channel preferences are stored per WordPress user. Contacts come
  from the WordPress/Key.kiwe identity, not from individual modules.
- A channel result is attached to the recipient's existing inbox item as
  `accepted`, `partial`, `fallback`, `failed` or `unavailable`. “Accepted” means
  the configured service accepted the handoff; it does not claim inbox or
  handset receipt.
- Recipient history is loaded only when that person opens **History**. It
  projects at most two pages of 20 and includes only channel, state, generic
  detail and attempt time. Contact data, push endpoints and provider responses
  are never projected.
- Acknowledgement uses a compare-and-swap update so a concurrent arrival is not
  overwritten. The same WordPress user therefore shares read state across
  signed-in devices without polling.

## Audience model

Users with administrator-area access manage relevant preferences in the
top-level WordPress **Notifications** screen. Subscriber, Kiwe User, Customer
and Guest journeys use the same preference authority through the DSA
Notification screen/sheet. A role sees only topics it is authorized to receive.

The client Administrator's **Campaigns** task is distinct from personal alert
preferences. It reads the same `dsa_notification_preferences` table only when
opened and uses current WordPress user meta as the final consent authority.
A publishing site's manual campaign sends to `newsletter` subscribers; a
WooCommerce site's manual campaign sends to `new_offer` subscribers. Both the topic and selected channel,
including per-topic overrides, must be present. App delivery additionally
requires current browser permission; email/WhatsApp/SMS require a usable native
contact and configured provider. No recipient or contact list enters the REST
response.

The preview reads at most 201 recent candidate rows and refuses to queue if
that bounded scan is incomplete or more than 100 eligible accounts match. One
private campaign can be active at a time. WordPress cron processes up to three
accounts per pass, rechecking consent and provider readiness before each one.
The active job option is temporary delivery state; it does not replace the
preference table. The cursor is persisted before network I/O so a worker crash
can leave an uncertain outcome but cannot replay that recipient. Only a
explicit authenticated action queues or resumes work. The client UI requires a
reviewed preview; the retained superadmin legacy handler computes a fresh
preview before queueing and still obeys the same consent, size and provider
checks. Opening Notifications or
Campaigns never calls an external provider, and there is no frontend polling.
Status and the last 20 completed summaries expose counts, topic, channel and
title only. They do not expose message bodies, contacts, recipient IDs,
provider payloads or push endpoints. A failed or uncertain attempt is not
automatically resent.

`new_post` is an automatic first-publication topic on publishing and commerce
sites. The public DSA owns each visitor's topic, selected post categories and
per-topic channels. Publication snapshots only the audience table's current
primary-key ceiling and schedules a worker; it performs no provider call.
Each pass reads at most 25 topic-indexed rows and addresses at most three
eligible people. Current preferences and the post's current categories are
re-read immediately before delivery. Registered accounts can use every
selected configured channel. Anonymous visitors can use only an active push
subscription with the exact opaque visitor hash; Kiwe never turns an anonymous
preference into email, WhatsApp or SMS identity.

The worker uses one global lock so simultaneous publications cannot fan out in
parallel on shared hosting. It commits the cursor and an unknown outcome before
external I/O, so a crash cannot replay a recipient. A failed follow-up schedule
leaves a resumable job. The client Administrator sees aggregate channel
enrolments plus the newest job counts, never a subscriber/contact list. Twenty
sanitized completion summaries are retained.

## Adding a notification

1. Register the topic through `dsa_notification_topic_catalog`, with an explicit
   audience/capability boundary.
2. Use `kiwe_notification_event` for ordinary staff/account events. A bounded
   per-recipient or category fan-out may use a dedicated worker only when it
   still reuses `Notification_Preference_Service`, `Push_Service` and
   `Channel_Service` and documents why the shared event router is insufficient.
3. Do not call `wp_mail()`, Key.kiwe or an SMS webhook directly.
4. Prove topic visibility, audience boundaries, revocation before handoff,
   configured channels and privacy in contract and native acceptance tests.

Removing a notification means removing its topic registration and source hook;
gateway configuration is never removed with a module.

SecureTrack keeps severity thresholds, repeat coalescing and hourly limits as
its security policy. Once an incident qualifies, delivery belongs entirely to
the shared Kiwe notification pipeline.
