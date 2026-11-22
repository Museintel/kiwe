# Links: profiles, enquiries and public sharing

This candidate contract requires the current target's `kiwe.links` descriptor (`kiwe.links.actions.v1`) and a compiler that understands the `linkAction` launcher extension. A static Start library entry does not establish installed support. Current SiteGraph and Design Context carry the same read-only descriptor: capabilities, configured enquiry channel IDs and native subject binding. No private lead records, visitor contact details or credentials belong in that descriptor or in an authoring ZIP.

Keep three distinct actions with accessible sibling buttons/links:

- **Visit profile:** native link from the existing Design Context social URL, optionally `data-kiwe-social="instagram"`. Do not replace or guess a profile URL with a DM URL.
- **Chat/enquiry:** `data-kiwe-chat="whatsapp|email|x|instagram|facebook"`. Include only a channel in `kiwe.links.configuredEnquiries`. Kiwe opens its existing Links screen and privately saves name, relevant return contact and message to Daily Forms before handing off. Saving is not external sending or marketing consent. When the target advertises `formContact` (`kiwe.form-contact.v1`), a successful enquiry captures a new unverified Subscriber through the native identity owner; it never logs into or changes a matching existing account. WhatsApp/email reuse existing business details. Standard Instagram/Facebook account profile URLs also supply their messaging identifier automatically; an explicit optional message link overrides it. Ambiguous/content URLs cannot supply a recipient. X needs its numeric recipient link. Instagram/Messenger copy the message then open the conversation; never invent a prefill/ref parameter.
- **Share:** `data-kiwe-share="native|copy|whatsapp|email|x|instagram|facebook|youtube|pinterest|linkedin"`. This shares the canonical public product/post, otherwise the business website. WhatsApp/email sharing lets the visitor choose recipients; it does not use the business enquiry recipient. Direct qualified provider intents and the device chooser/copy fallback have different availability. Never promise a forced app, successful publishing or private data transfer.

Use the packaged Kiwe icons exposed to Bricks. The Links sheet places the channel name/profile, a distinct Chat icon when supported, and an icon-only Share action in one row. A page may reuse the attributes in the header, footer or cards. Named supported share actions open the selected provider directly, without another generic confirmation DSA. Current public share data is projected once at native page render; explicit loop subjects resolve on intent. For channels without a supported direct URL, the original gesture opens the device share menu or copies the public link with clear feedback; manual recovery appears only if that fails. No forced app or arbitrary social publishing is promised. Avoid extra modal systems, social SDKs, iframes, browser polling or page-load provider calls. Prefer actual buttons/links; icons need accessible labels. Never nest a button inside a profile link.

## Context and immutable preview conversion

Omit `data-kiwe-subject-id` for current context: an eligible public single product/post supplies itself and other pages use the business website. In its map, use `subject: "current"`, including on single-product and single-post templates. Private/password-protected/draft records never become share content, including when the author is signed in as an administrator. Do not share `location.href`: it can contain checkout, login, preview or tracking parameters. For an MCP-exported SiteGraph, use the exact `/siteGraph/kiwe/links` pointer; a direct native SiteGraph uses `/kiwe/links`.

In an actual **native post/product query loop**, bind `data-kiwe-subject-id` to `{post_id}`. Do not bake the first preview card ID into every live card or bind a term/user loop as a post. In `/seam`, express this as the guarded Links launcher below so the compiler owns the attribute conversion; accepted preview styling/content stays intact. Keep the relevant marker/control in the loop prototype, without a conflicting subject attribute. The preview need not run Kiwe mutations.

```json
{
  "kind": "launcher",
  "key": "product.share",
  "anchor": "product-share",
  "semantic": "product.share",
  "authority": {
    "source": "sitegraph",
    "pointer": "/kiwe/links",
    "evidence": "Current target proves the shared Links contract"
  },
  "module": "links",
  "linkAction": { "action": "share", "channel": "native", "subject": "{post_id}" }
}
```

For a general header/footer chat button, use `action: "chat"`, a proven configured channel and `subject: "current"`. The compiler checks the exact SiteGraph contract, channel admission and unchanged map, requires per-record actions inside their exact native post/product prototype, and preserves the resulting native attributes. A plain `module: "links"` launcher continues to open the whole Links screen. Missing support requires a reported unavailable capability or a normal native public profile/contact link, not an invented production handler or a silent change to the site's settings.

## Provider boundaries

Primary contracts: [WhatsApp click to chat](https://faq.whatsapp.com/5913398998672934), [email URI RFC6068](https://www.rfc-editor.org/rfc/rfc6068), [Web Share](https://www.w3.org/TR/web-share/), [X direct-message button](https://github.com/xdevplatform/docs/blob/main/x-for-websites/direct-message-button.mdx) and [X post intent](https://github.com/xdevplatform/docs/blob/main/x-for-websites/post-button/guides/web-intent.mdx). Share chooser availability and app routing belong to the browser/device. It requires a fresh user gesture, may be cancelled and cannot guarantee that a particular installed social app will appear. Meta links remain copy/open; no automatic prefilled Meta message is claimed. Requalify actual account/device behavior on the lab before release.


## Reusable artwork, contextual messages and forms

The native Bricks Icon chooser exposes **Icon: Chat** (`{kiwe_icon_chat}`) and **Icon: Share** (`{kiwe_icon_share}`), aliases of the same message-circle/share-2 artwork used in Links. Select the icon and add the action attribute; do not copy the entire Links UI or add a custom click script.

Required default placement for future sites: every single-post and single-product template includes an accessible Share icon using `data-kiwe-share="native"`. Each single-product purchase area also includes a sibling WhatsApp Chat button next to native Add to cart whenever WhatsApp appears in the current target's configured enquiry channels. Use `{kiwe_icon_whatsapp}` with “Ask on WhatsApp”, and `type="button"` in any purchase form. Keep it outside the native submit button; avoid intercepting quantity/variation/cart submission. Missing channel configuration must be reported, not invented or silently enabled. These in-content controls supplement the existing bookmark, wishlist and Comments/Reviews requirements. Generated raw previews show them; `/seam` declares the matching guarded launcher stitches so conversion retains them.

For example, beside a single-product purchase control use `data-kiwe-chat="whatsapp"` with an accessible label such as “Ask about this product”. Omit a subject attribute on the actual public single product/article. Inside a real native product/article query loop add `data-kiwe-subject-id="{post_id}"`. A site-wide Contact/Chat button uses the same action outside singular context. Share uses `data-kiwe-share="whatsapp"` or another admitted channel and the same subject rule. The server resolves public context and, when `composer.openingMessage` is advertised, starts a new enquiry with an editable “I am enquiring about …” introduction. Preserve visitor edits and retained drafts. Never bake preview product titles, record IDs or private URLs into a reusable message.

When `formContact` is present, prefill reuses only the visitor's own recognized account or their own successfully submitted browser details, including an unverified contact. It is not a lookup by a typed email/phone, authentication, contact verification or a marketing subscription. Empty fields fill privately on form intent; authored values and visitor edits win. Add short visible wording that new contacts become unverified Subscribers and delivery preferences remain independent. Existing stronger accounts/roles are not changed.

For native Bricks website forms, use the standard field autocomplete values `name`, `given-name`, `family-name`, `email` and `tel`. Use one unambiguous field per meaning; keep native validation, honeypot, Save submission and delivery ownership. Password/login/register forms are excluded. A preview cannot register a subscriber or send a message; never include real visitor details in Start, SiteGraph or a SEAM package.
