# Kiwe notification architecture

Kiwe has one notification contract for every subsystem. SecureTrack, Key.kiwe,
editorial, Guest, WooCommerce, PWA and future modules publish a topic and a
presentation-safe event to `kiwe_notification_event`. Sources never own SMTP,
WhatsApp, SMS, push credentials, recipient databases or delivery retries.

## Delivery model

- The Kiwe inbox is the durable in-product surface.
- Browser/app push is a device permission layered over the inbox.
- Email, WhatsApp and SMS are the three external delivery channels.
- `Channel_Service` is the credential-blind gateway boundary for all modules.
- Unconfigured gateways are disabled in both WordPress Notifications and the
  DSA Notification surface; Kiwe never accepts a channel that cannot deliver.
- Topic and channel preferences are stored per WordPress user. Contacts come
  from the WordPress/Key.kiwe identity, not from individual modules.

## Audience model

Users with administrator-area access manage relevant preferences in the
top-level WordPress **Notifications** screen. Subscriber, Kiwe User, Customer
and Guest journeys use the same preference authority through the DSA
Notification screen/sheet. A role sees only topics it is authorized to receive.

## Adding a notification

1. Register the topic through `dsa_notification_topic_catalog`, with an explicit
   audience/capability boundary.
2. Publish `do_action( 'kiwe_notification_event', $topic, $event )`.
3. Do not call `wp_mail()`, Key.kiwe, an SMS webhook or Push Service directly.
4. Prove the topic visibility, audience boundary, shared ingress and all three
   external channels in a contract test.

Removing a notification means removing its topic registration and source hook;
gateway configuration is never removed with a module.

SecureTrack keeps severity thresholds, repeat coalescing and hourly limits as
its security policy. Once an incident qualifies, delivery belongs entirely to
the shared Kiwe notification pipeline.
