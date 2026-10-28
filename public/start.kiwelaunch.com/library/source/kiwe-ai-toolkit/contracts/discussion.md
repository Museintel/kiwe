# Contextual Comments and Reviews

This is the Start 9.9 authoring contract for Core452, Store1.1.8 and compiler2.18.3. Require the matching compiler and current target evidence: a library entry does not establish support on an older installation or compiler. SiteGraph's `kiwe.discussion` uses `kiwe.discussion.v1`; Design Context carries the same read-only descriptor. Core's `Discussion_Contract` owns it. The descriptor exposes no conversation history or private identities. Separately, Design Context can expose eligible public testimonial quotes under the source policy below.

Include an accessible conversation button on applicable post/product previews, fitting the accepted design. Product reviews require the active Store adapter and `productReviews: true`. Core post comments work without WooCommerce. A site-wide button requires a configured positive `core.websitePageId`. Never invent a target or turn website feedback on during authoring.

## One action, an explicit target

The production action is `data-dsa-open-module="comments"`, with `data-dsa-discussion-id` identifying the conversation. Use `current` on an eligible single post/product, `site` for configured website feedback, or the exact native `{kiwe_discussion_id}` binding in a **WordPress post loop**. Products are posts; user/term loops are not. Never bake a preview record ID into every card.

In Bricks, put each name and value in separate native Attributes fields. Use `{kiwe_icon_comments}` for the packaged icon when exposed by the target. Native `{kiwe_discussion_count}` supports deliberately designed text, but ordinary attributed icons receive their live badge automatically. It counts approved top-level contributions; replies and unread notifications are separate. Do not add a second counter.

A strict SEAM Map uses a `launcher` stitch with `module: "comments"` and `discussionTarget` equal to `current`, `site` or `{kiwe_discussion_id}`. Authority must reference the attached SiteGraph descriptor. The native-tag choice additionally requires that exact tag in the target's Bricks catalogue. The compiler validates that evidence, retains the current-record binding in native Attributes and refuses conflicts or a changed map. Other launchers cannot use `discussionTarget`.

Inside a proven `kiwe_testimonials` collection, use `discussionTarget: "{kiwe_testimonial_target_id}"` instead. Require the matching `bricks.testimonialTarget`/`testimonialQuery`, query type and dynamic tag in SiteGraph. Place the launcher inside that collection's exact prototype. Its target is the original post/product/site page; `{kiwe_testimonial_id}` is a comment ID and must never be substituted. The native compiler refuses a launcher outside its testimonial prototype.

```json
{
  "kind": "launcher",
  "key": "story.conversation",
  "anchor": "story-conversation",
  "semantic": "discussion.open",
  "authority": {
    "source": "sitegraph",
    "pointer": "/kiwe/discussion",
    "evidence": "Current target exposes the contextual discussion contract"
  },
  "module": "comments",
  "discussionTarget": "{kiwe_discussion_id}"
}
```

The preview button needs the matching inert `data-seam-anchor="story-conversation"`. Simulate opening locally without sending customer data. The compiler adds the production action and target during conversion. A real native discussion URL may remain as a link fallback. Recompute source stitches/hashes and attached evidence after an approved source change.

## Preserve the native journey

Reuse the single Comments/Reviews DSA and its existing Sheet, keyboard/focus/scroll owners and protected Seam tokens. Lists/composers load on demand. Dock and attributed controls share context/counts. Do not generate another Dock, form, badge script, identity journey or reactions engine, or apply page component styling to the AppShell.

WordPress owns comments, threads and moderation. Store owns product stars and buyer rules. PhoneKey owns account/contact proof. The composer collects missing public name/contact and reuses OTP or native permitted moderation. Never expose email/phone as public names, grant an account from a typed match, invent customer reviews/avatars or infer marketing consent from commenting.

Latest is chronological. Top can prioritize explicit team hearts, bounded pins and account-owned likes. Reports remain private and do not automatically hide criticism. These are runtime behaviors to reuse. Missing support stays an explicit handoff gap, rather than being replaced by a browser-only workflow.

## Live testimonials, not invented copy

Design Context → Website plan selects pinned, team-hearted and optionally other liked public contributions as testimonials. There is no separate manual testimonial-selection journey. Consume the existing `testimonials.items`, source policy and `dynamicBindings.collections.testimonials`. Use the native `kiwe_testimonials` Bricks query and the existing `{kiwe_testimonial_name}`, `{kiwe_testimonial_quote}`, `{kiwe_testimonial_url}` and optional genuine `{kiwe_testimonial_rating}` tags. Additional proven tags identify `source_type`, `source_title`, `target_id`, `likes`, `pinned` and `hearted`; they describe the same current projection record. Use `{kiwe_testimonials_available}` for a native empty-state condition when proven by the current catalogue.

Never fabricate customer quotes, photographs, review counts, purchase badges or stars. Staff replies are not customer endorsements. Keep a product review's source attribution; an article or website comment is not a product rating. Zero eligible quotes means omit the testimonial rail or show an explicitly approved empty state. A real native product rating may be hidden by the store's own rating policy. Revoked moderation, private targets, held ancestry and disabled Store records cannot remain public testimonials.

The three `contentPlan.testimonialSources.*` choices also have native Design Context dynamic tags when the current catalogue exposes them. They are source preferences, not proof of a quote or permission to change moderation. Core owns context even when SEO/AI/Store Apps are absent; ordinary post/site feedback never requires WooCommerce.
