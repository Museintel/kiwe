# Kiwe AI entrypoint

If you are an AI, designer, or developer using this repository to create a website/page, Kiwe DSA AppShell theme, or combined handoff, do not read the whole codebase first.

Fastest start file:

```text
https://raw.githubusercontent.com/Museintel/kiwe/main/KIWE-START.md
```

Fastest machine-readable start file:

```text
https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/entry.json
```

Read one of those first when the human gives only the Kiwe repo/link, no slash command, or an attached artifact. It will confirm the Kiwe Start contract version, classify the artifact, route `/list` or the next `/command`, and ask whether the human wants step-by-step flow or full-flow execution when no command is supplied.

Start with the public toolkit:

```text
kiwe-ai-toolkit/
```

The toolkit gives compact context packs and validation rules so you do not waste tokens on plugin internals.

Fast machine-readable command manifest:

```text
https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/command-manifest.json
```

When available, read this JSON manifest first. It is the shortest command contract and tells you which one or two context files to read for the requested slash command. Use the Markdown contexts only for the matched command lane.

## Browser AI path, no tool execution

If you are a browser-based AI and cannot connect the Kiwe MCP server or run the CLI, do not clone or crawl the full repository.
Do not use web search to discover toolkit files. Use the exact raw links below.

Terminal-style entry pattern:

```text
explore: https://github.com/Museintel/kiwe
/list
```

Treat `explore:` as a location pointer, not permission to browse the repository. For this exact pattern, read only this root entrypoint or its raw fallback at `https://raw.githubusercontent.com/Museintel/kiwe/main/KIWE-AI.md`, execute `/list`, then stop and wait for the next slash command. If a different slash command follows `explore:`, route that command through the workflow context or command gate below. Do not inspect the whole repo unless a later validated command explicitly gives a narrow file URL to read.

For fastest command routing after `explore:`, read only:

```text
https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/command-manifest.json
```

Then read only the context files listed for the matched command. If the manifest and a prose context disagree, fail closed: keep the narrower output shape, preserve existing artifacts, and do not create documentation unless `/document` is present.

Preferred path for serious work:

```text
https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/workflow-lite.md
```

Use the workflow file when the human wants high-quality output, fewer correction loops, or command-style phases such as `/list`, `/fix`, `/document`, `/ideate /webdraft`, `/rebuild /seamframework`, `/audit /seamframework`, `/create /frameworkprofile`, `/audit /frameworkprofile`, `/create /brickstheme`, `/audit /brickstheme`, `/create /dsatheme`, `/create /preview /dsatheme`, `/assemble /combined`, `/create /preview /combined`, `/usesitegraph`, `/convert /bricks`, `/audit /bricksconversion`, `/create /accessibility`, `/audit /accessibility`, or `/fix /accessibility`.

Documentation is opt-in for every lane. Unless the command includes `/document` or the human explicitly asks for notes, output only the canonical artifact file(s) for that command. Do not add README files, notes, audit reports, duplicate previews, ZIPs, or polite explanation files by default.

Final chat responses are also lean by default. Return only a compact `PASS` / `FAIL` / `WARN` status, files changed, files kept/removed when relevant, validator/audit status, and remaining warnings. Do not write long paragraphs unless `/document` or the human asks for explanation.

Canonical command language uses `/create` for creation phases. If an older prompt says `/build`, treat it as a legacy alias and answer back with the canonical `/create` wording so the command vocabulary stays stable.

Canonical Site Graph command is `/usesitegraph`. Legacy `/dynamic /sitegraph` may be accepted internally, but new user-facing output should use `/usesitegraph`. Useful variants are `/usesitegraph /replacepreviewdata`, `/usesitegraph /websitename`, and `/usesitegraph /nonai`.

Before spending tokens on a slash-command phase, run the Kiwe command gate when tools or CLI are available:

```text
kiwe_diagnose_command
```

or:

```bash
node kiwe-ai-toolkit/bin/kiwe.js diagnose --command "/convert /bricks" --artifact-summary "website/bricks-paste.html exists; framework/kiwe-framework-profile.json exists"
```

If the diagnostic returns `stop: true`, do not continue. Report the diagnostic to the human. This prevents non-existent commands, wrong-lane requests, missing artifacts, missing Site Graph context, and no-op preview requests from turning into token-wasting generation loops.

The workflow intentionally separates creativity from Kiwe contract compliance. A pure creative draft may happen first without Kiwe/Seam/DSA constraints; later commands rebuild, audit, package, and bind it.

Any phase command may include `/usecompanion`, for example `/rebuild /seamframework /usecompanion` or `/audit /dsatheme /usecompanion`. This is optional and non-blocking. If the human supplies `KIWE_REST_BASE` and `KIWE_AI_KEY`, make one bounded call to Kiwe Companion for compact phase cards or Audit Companion findings. If Companion is unavailable, disabled, slow, rate-limited, over budget, or inaccessible, continue with the same command without `/usecompanion` and report the fallback. Do not use `/usecompanion` to make Companion co-author the whole output, dump full plugin files, or spend native model tokens; it is a deterministic contract oracle with hashes, rule IDs, memory fingerprints, and `mustFix` maps.

If the human explicitly asks for one-shot output, read exactly one static context file after this entrypoint:

- Workflow / command router: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/workflow-lite.md`
- Website/page only: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/website.md`
- Kiwe DSA/AppShell theme only: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/theme.md`
- Website/page + AppShell direction/settings, browser-short version: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/combined-lite.md`
- Website/page + AppShell direction/settings, full version: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/combined.md`
- Revision/audit pass: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/audit-lite.md`
- Seam/Appsite capability attributes for `/rebuild /seamframework`: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/seam-attributes-lite.md`
- Dynamic WordPress/Bricks binding pass after an approved handoff: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/dynamic-lite.md`
- Bricks conversion package after dynamic binding approval: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/bricks-conversion-lite.md`
- Light/dark contrast and token-pair accessibility lane: `https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/accessibility-lite.md`

For a fast prompt that asks for both a website/page and a Kiwe AppShell/DSA direction in one pass, read:

```text
https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/combined-lite.md
```

Treat that file as the authoritative generated toolkit response. It exists so browser AIs do not need to execute repo code or read the full plugin.

For v2/v3/v4-style revision prompts, read the relevant mode context first, then read:

```text
https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/audit-lite.md
```

Use it to identify and fix issues in the previous handoff. Do not claim the executable Kiwe audit ran unless you actually executed the CLI.

For a v5-style dynamic pass where the design already passed and the human wants real WordPress/Bricks/WooCommerce query loops, dynamic data, and Kiwe launchers, read:

```text
https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/contexts/dynamic-lite.md
```

Also ask for the target site's `kiwe.site-graph.v1` JSON. Do not guess categories, term IDs, pages, products, dynamic tags, or Bricks query-loop object types. The Site Graph is available to admins from Kiwe as:

```text
Kiwe > AI > AI connector and Site Graph > Download Site Graph JSON
```

External tool clients can create a revocable key at `Kiwe > AI > API access keys` and then use the API-key connector:

```text
GET /wp-json/dsa/v1/ai/status
GET /wp-json/dsa/v1/ai/site-graph?sampleLimit=8
Authorization: Bearer kiwe_ai_...
```

Copy the full key immediately after creation. Kiwe stores only a hash and shows the full secret once; the table later shows only a prefix/last-four fingerprint for identification.

For public/headless content data, do not scrape the site's frontend. Use the AI-less Site Graph Data API:

```text
GET  /wp-json/dsa/v1/site-graph/data/schema
GET  /wp-json/dsa/v1/site-graph/data?resource=products&taxonomy=product_cat&term=fudge&limit=4
POST /wp-json/dsa/v1/site-graph/data
```

This route is read-only and public-safe. Anonymous calls return public/published posts, pages, products, menus, terms, media, and site identity. Authenticated administrators can receive broader private reads. A `POST` body may include a `queries` object to fetch many page datasets in one GraphQL-like request, or a compact `resources` array such as:

```json
{
  "resources": ["site", "products", "pages", "media"],
  "limits": { "products": 4, "pages": 4, "media": 4 }
}
```

Writes still belong to the controlled staging executor.

API-key clients can also use the same data lane through the AI namespace when a key has `site_graph_data` or `all` scope:

```text
GET  /wp-json/dsa/v1/ai/site-graph-data/schema
GET  /wp-json/dsa/v1/ai/site-graph-data?resource=posts&limit=6
POST /wp-json/dsa/v1/ai/site-graph-data
```

For internal Kiwe AI context and redacted security intelligence:

```text
GET /wp-json/dsa/v1/ai/internal-context
GET|POST /wp-json/dsa/v1/ai/advisor
GET|POST /wp-json/dsa/v1/ai/advisor/enrich
GET /wp-json/dsa/v1/ai/security-brief
GET /wp-json/dsa/v1/ai/companion/status
GET|POST /wp-json/dsa/v1/ai/companion/context
POST /wp-json/dsa/v1/ai/companion/ask
POST /wp-json/dsa/v1/ai/companion/review-output
GET|POST /wp-json/dsa/v1/ai/audit-companion/context
POST /wp-json/dsa/v1/ai/audit-companion/review
GET /wp-json/dsa/v1/ai/companion/memory
GET  /wp-json/dsa/v1/ai/studio/status
POST /wp-json/dsa/v1/ai/studio/start
POST /wp-json/dsa/v1/ai/studio/draft
POST /wp-json/dsa/v1/ai/studio/review
GET|POST /wp-json/dsa/v1/ai/bricks/context
POST /wp-json/dsa/v1/ai/bricks/plan
POST /wp-json/dsa/v1/ai/validate-bricks-conversion
```

`/ai/internal-context` returns the safe fused packet for Kiwe internal AI: Site Graph summary/hash, Site Graph Data schema, WordPress 7/Abilities signals, capability map, operating boundaries, and a SecureTrack status lane. SecureTrack brief details are off unless `Kiwe > AI` enables redacted SecureTrack sharing and the key has `all`, `security_brief`, or `companion_securetrack` scope. `/ai/advisor` runs the deterministic read-only advisor over that context and returns findings, recommendations, and safe next actions without calling a model or mutating the site. `/ai/advisor/enrich` returns the model-optional enrichment envelope: deterministic fallback summary, priority ordering, and the bounded model payload/schema a future WordPress AI Client adapter may use. It does not call a model in the current adapter. `/ai/security-brief` is redacted and separately gated: no raw IPs, usernames, secrets, full URLs, request payloads, or visitor trails.

The same advisor/enrichment seam is visible to administrators at `Kiwe > AI` as the Kiwe Advisor panel. It is server-rendered and read-only; refreshing the panel recomputes findings and the deterministic enrichment summary from current context but does not execute staging, call a model, save Bricks, mutate WooCommerce, run checkout/cart/auth, or change security enforcement.

Kiwe Companion AI is the optional site-aware context broker for external AIs creating website/page, DSA/AppShell theme, combined, dynamic binding, audit, staging, or security-support outputs. Enable it in `Kiwe > AI`, then issue a revocable key with `companion` scope. The Companion returns compact context cards, route hints, validation diffs, rule IDs, and safe next-action plans rather than dumping the whole plugin or raw security logs. For revisions, browser AI should submit the actual file map to `/ai/audit-companion/review` first, fix every `mustFix` item, then run its own explanation/self-audit. This saves tokens because the deterministic Audit Companion identifies concrete contract failures without calling a model. Its local memory stores privacy-safe fingerprints, structured pass/fail finding codes, and counts only, never secrets, raw visitor trails, raw SecureTrack events, customer data, full handoff files, or unredacted transcripts. SecureTrack AI exposure is a toggle under `Kiwe > AI`: redacted SecureTrack briefs use Companion consent/scopes, and SecureTrack Site Brain cloud review syncs from the shared Native AI provider/key when that provider is supported. There is no separate SecureTrack API-key field in Kiwe AI. `Kiwe > Secure` remains focused on human security controls and enforcement.

When a workflow command includes `/usecompanion`, pass `mode`, `phase`, `command`, `brief`, and a short `artifactSummary` if your client can send JSON. Generation/rebuild/create/assemble/dynamic phases should prefer `/ai/companion/context` or `/ai/companion/ask`; audit/revision phases should prefer `/ai/audit-companion/review` with the actual generated file map. Always produce a compact `COMPANION-TRACE` listing routes attempted, success/fallback state, contextHash/siteGraphHash when supplied, cards/findings used, and confirmation that Companion did not replace the selected Kiwe phase.

Kiwe Studio AI is the higher-level companion workflow. Enable it in `Kiwe > AI` and choose one operating mode: `native` for bounded native drafting through the configured provider/API key, `browser_companion` for browser AI plus token-saving Studio packet and Companion review, or `browser_only` when the user wants public toolkit prompts with no internal AI support. Use `/wp-json/dsa/v1/ai/studio/start` first for a token-saving Studio packet, `/wp-json/dsa/v1/ai/studio/draft` only when native drafting is enabled and the Kiwe AI key has `native_ai` scope, and `/wp-json/dsa/v1/ai/studio/review` after v1 output. A normal `studio_ai` key can obtain packets and deterministic reviews; add `native_ai` only when the key may spend provider tokens. Studio does not save Bricks, publish WordPress content, mutate WooCommerce, run cart/checkout/auth, or change SecureTrack enforcement.

Bricks AI Intelligence is the Bricks-native map for both browser AI and Kiwe Studio AI. External tool clients can use a key with `bricks_ai`, `studio_ai`, or `all` scope to call `/wp-json/dsa/v1/ai/bricks/context` before emitting Bricks JSON or dynamic binding plans, and `/wp-json/dsa/v1/ai/bricks/plan` for a compact planning packet. It reports available Bricks elements, compact element controls, query loops, dynamic tags, conditions, interactions, Seam headless rules, and Kiwe launcher/runtime boundaries. It is read-only. It does not paste content, save Bricks, publish pages, or create Woo/cart/auth behavior.

Kiwe Accessibility is a focused post-design lane for contrast, native light/dark support, and visible text containment. Use `/create /accessibility` only after a website/page, DSA theme, combined handoff, Framework profile, Bricks theme style, or Bricks conversion exists. It creates `accessibility/kiwe-accessibility-plan.json` only unless `/document` is requested; it does not redesign the page or create Bricks JSON. Use `/audit /accessibility` to reject literal white-on-white/black-on-black pairs, missing dark-mode proof, unmapped private color variables, Bricks outputs that do not align Kiwe tokens with Bricks root theme-style slots, and critical titles/labels/pills/chips/buttons/tabs/prices/stats/card text that are clipped or hidden by constrained Geometry/Seam sizing. Bricks 2.4 frontend dark mode is proven through `data-brx-theme="dark"` / `:root[data-brx-theme="dark"]` in addition to Kiwe's `data-kiwe-theme` contract, because Bricks emits dark color-palette variables on that selector. Use `/fix /accessibility` to repair only the existing failed artifact lane plus the accessibility plan. CLI-capable clients can run `node kiwe-ai-toolkit/tools/validate-accessibility.cjs <handoff>`. Browser AI clients can also submit file maps to `/wp-json/dsa/v1/ai/audit-companion/review`; Companion will return deterministic accessibility findings without calling a model.

Seam's universal Appsite attribute layer is part of the framework brain. During `/rebuild /seamframework`, preserve the approved UI and add live capability attributes when the intent exists: `data-dsa-open-module` for Kiwe screens/theme toggle, `data-kiwe-save` for wishlist/bookmark controls, `data-kiwe-notifications` for browser-notification CTAs, `data-kiwe-theme-toggle` for light/dark controls outside the dock, real semantic sections for Kiwe Menu context, and `data-kiwe-query-template` / `data-kiwe-binding` for future Bricks query-loop and dynamic binding plans. Do not create duplicate JavaScript for these Kiwe-owned capabilities. Toolkit/MCP clients can call `kiwe_get_seam_attributes_context` or `kiwe_list_capability_attributes`.

For `/create /frameworkprofile`, output only the Kiwe Framework profile import file:

```text
framework/
  kiwe-framework-profile.json
```

This file is imported in `Kiwe > Framework`. From there the admin can push the profile to Bricks: variables, color palette, global classes, and Bricks theme-style data. This is the easiest setup path for most users. The profile must include a complete `settings.tokens.bricks_theme_style` object with `enabled: true`, safe `id`, human `label`, and only global style slots; Kiwe turns that into the native Bricks Theme Style during push. Do not output `FRAMEWORK-NOTES.md`, Bricks template JSON, AppShell theme packages, or duplicate docs unless `/document` is explicitly present.

For `/audit /frameworkprofile` and `/fix /frameworkprofile`, do not treat Framework profiles as aliases of `/brickstheme`. They are different artifact lanes. A Framework profile is a Kiwe import file with `schema: "kiwe.framework-profile.v1"` and `settings.tokens`; a Bricks Theme Style is a native Bricks import file with root `{ "label": "...", "settings": { ... } }`.

Inside a Framework profile, `settings.tokens.bricks_theme_style` is allowed to contain safe global style slots such as `siteBackground`, `colorPrimary`, `colorSecondary`, `colorLight`, `colorDark`, `colorMuted`, `colorBorder`, `fontDisplay`, `fontBody`, `typeH1`, `typeBody`, `spaceMd`, `radiusLg`, and `shadowMd`, in addition to `enabled`, `id`, and `label`. Do not remove those global slots as “unsupported.” They seed the Bricks Theme Style and normalize back into official Kiwe universal tokens during Kiwe > Framework push.

`/audit /frameworkprofile` must also verify the core live token foundation without human spoon-feeding: `color-brand`, `color-accent`, `color-surface`, `color-surface-raised`, `color-text`, `color-text-muted`, `color-border`, `font-display`, `font-body`, `type-h1`, `type-body`, `space-md`, `radius-lg`, and `shadow-md` must be covered directly in `settings.tokens.overrides` or through mapped `bricks_theme_style` slots. Use the official token list from the Kiwe toolkit, not the tokens already present in the profile as a fake “official” universe.

For `/create /brickstheme`, output only one native Bricks Theme Styles JSON file:

```text
bricks-theme-style.json
```

The root shape is `{ "label": "...", "settings": { ... } }` with optional safe root `id` for Bricks-export compatibility, matching Bricks Theme Styles import/update behavior. This command is not a Kiwe Framework profile, not Bricks page/template JSON, and not a DSA/AppShell theme. Prefer `/create /frameworkprofile` when the goal is a complete Kiwe-to-Bricks setup. Use `/create /brickstheme` only when the human specifically wants the standalone Bricks Theme Styles import file.

For `/convert /bricks`, produce the native Bricks My Templates upload JSON by default. `/convert /bricks` is the only public Bricks conversion command. Run `/convert /bricks` only after a Framework profile or Bricks theme style exists, or after the human confirms Kiwe > Framework/Bricks Theme Styles have already been imported/pushed; otherwise stop and ask for `/create /frameworkprofile` first.

```text
bricks-template/
  [page-or-template-name]-template-upload.json
```

Do not output `BRICKS-CONVERSION-NOTES.md`, `FRAMEWORK-NOTES.md`, README files, reports, ZIP files, duplicate previews, or other docs unless `/document` is explicitly present.

The upload JSON must match Bricks' own template import shape: top-level non-empty `title`, `templateType`, and one non-empty `content`, `header`, or `footer` array. A homepage body should use `title: "Home"` and `templateType: "content"`. The native Bricks template JSON may include top-level `kiwe` metadata for source/fidelity proof, but the file itself must remain importable by Bricks. Do not give the human `bricks-conversion/kiwe-bricks-conversion.json` as the upload file; Bricks imports that wrapper as `(no title)` and then fails insertion with "This template has no data."

The conversion must preserve the approved page hierarchy, Seam classes/attributes, canonical `data-dsa-open-module` launchers, query-loop intent, dynamic tags, conditions, interactions, and unsupported/manual-review evidence. Prefer Bricks 2.4 native HTML/CSS conversion when available, then add Kiwe's fidelity map. The goal is an editable Bricks visual-builder handoff, not a page that only renders through one CSS dump: ordinary typography, colors/backgrounds/gradients, borders/radii, shadows, transforms, filters, transitions, spacing, sizing, grid/flex, responsive direction, alignment, query loops, conditions, interactions, and dynamic tags should become Bricks-native controls, global classes, or global variables first. Bricks-native controls include `_display`, `_direction`, `_justifyContent`, `_alignItems`, `_flexWrap`, `_columnGap`, `_rowGap`, `_gridTemplateColumns`, `_gridItemColumnSpan`, `_typography`, `_background`, `_gradient`, `_border`, `_boxShadow`, `_transform`, `_cssFilters`, and breakpoint forms such as `_direction:mobile_landscape`. Native controls are still required to consume the Framework token layer: spacing, sizing, radius, typography scale, shadow geometry, transform offsets, responsive layout values, and every component color/background/gradient/border/shadow/fill must use `var(--kiwe-*)`, `var(--seam-*)`, declared project variables, or real tokenized `clamp(...)` values where appropriate. Do not emit bare design lengths such as `_padding: 28px`, `_border.radius: 24px`, `_heightMin: 390px`, `_typography.font-size: 2.35rem`, or `_rowGap: 20px` in element settings or `global_classes`; the Framework profile supplies token definitions, and Bricks conversion must consume them. Do not emit direct component colors such as `color: #fff`, `_background.color.raw: #8deae5`, `linear-gradient(#201b18, #514238)`, `rgba(255,255,255,.11)`, or local component-variable definitions such as `--pack-bg: #f5b942`; those must become official Kiwe/Seam tokens or declared project variables. Literal colors are allowed only in Framework/global variable definitions or as fallbacks inside `var(...)`, for example `var(--kiwe-color-text, #201b18)`. Do not silence the audit with no-op clamps such as `clamp(22px, 22px, 22px)`; that is still a hardcoded value. Tokenization fallback ladder: first use an official Kiwe/Seam token when meaning and property domain match; second use a declared project token for stable art direction; third use a real fluid clamp only when source responsive states prove different values for the same property. Fluid clamp math is `slope = (maxValue - minValue) / (maxViewport - minViewport) * 100`, `intercept = minValue - (slope / 100 * minViewport)`, then `clamp(minValue, calc(intercept + slope * 1vw), maxValue)`. CLI/MCP-capable clients may call `kiwe fluid-clamp --min 220px --max 390px --min-vw 478 --max-vw 1440`. Custom CSS is allowed only as an explicit exception, documented in embedded `kiwe.fidelity.nativeStyleIntent`, `kiwe.fidelity.unsupported`, or `kiwe.report.manualReview`; if custom CSS contains many mappable declarations, the audit expects enough native controls to prove those decisions remain editable in Bricks and consume the Framework token layer. Template-upload exports that rely on importable class styles must provide Bricks' `global_classes` dependency key, not only copied-elements `globalClasses`. Do not rely on `pageSettings.customCss` as the main styling lane for template uploads because Bricks insertion can leave that CSS behind or collide with stale target-page CSS; template-upload handoffs must carry ordinary layout/design in native element settings, global classes, or global variables. Validate with `validate-bricks-conversion` or MCP `kiwe_validate_bricks_conversion` before staging. The package does not mutate WordPress or Bricks by itself.

`/convert /bricks` source is strictly page-only: `website/bricks-paste.html`. Never convert `combined-preview`, `appshell-theme`, DSA/AppShell previews, screen/sheet/dock/navbar markup, `theme-package.json`, or `css/theme.css` into Bricks. Use `/create /preview /dsatheme` for AppShell preview proof and `/create /preview /combined` for page-plus-AppShell preview proof.

When working inside the Bricks front-end editor, admins can enable the Kiwe Studio companion at `Kiwe > AI`. The editor panel uses WordPress nonce-auth routes (`/wp-json/dsa/v1/bricks/studio/context`, `/start`, `/draft`) to fetch the same Bricks + Seam context, plan a page/section, or call native AI when explicitly allowed. The panel is a planning/copilot surface, not a direct mutation surface; staging saves still go through the controlled executor.

For staging proof after uploading the MU folder, use the latest `wp-content/mu-plugins/dsa/site-graph-system/release-proof-*.md` file. Version `6.64` records the full-flow `KIWE-START.md` / `entry.json` front door for browser AI, IDE AI, MCP clients, and Companion workflows; artifact classification; `kiwe_plan_flow`; compact command manifest routing; no-clone command routing; `/fix /accessibility` dark-mode token remap recipe; Bricks 2.4 `data-brx-theme` dark-mode compatibility; DSA runtime dark-state sync across `data-kiwe-theme` and `data-brx-theme`; `/fix /accessibility` structural-preservation contract; canonical `/convert /bricks` command; native Bricks upload JSON validation; standalone-file audit support; Framework-profile Bricks Theme Style checks; Studio AI operating-mode routes; native-provider boundary; Bricks AI intelligence routes; Bricks editor companion toggle; SecureTrack shared AI settings boundary; API proof routes; WordPress 7 ability checks; dynamic handoff checks; browser smoke checks; mutation boundaries; deterministic `/create /accessibility` + `/audit /accessibility` color/dark-mode/text-containment lane; tightened Bricks template-upload validator checks; and fail-level `/convert /bricks` token-purity checks for direct component colors in native Bricks settings/global classes/custom CSS.

Theme installers can use the same key to review, install, and activate Kiwe DSA theme packages:

```text
GET  /wp-json/dsa/v1/ai/themes
POST /wp-json/dsa/v1/ai/themes/install
POST /wp-json/dsa/v1/ai/themes/{themeId}/activate
```

A Kiwe theme package is one JSON file with root `schema: "kiwe.theme-package.v1"`, root `theme`, root `settings`, and root `css`. The `settings` preset is limited to safe theme-owned subsets (`style`, `dock`, `dsa_theme`, `visual_effects`, `tokens`, and `screens`) and appears in WordPress under `Kiwe > Theme > Installed themes`. Do not output or ask users to import a loose settings file for DSA themes.

Standalone website/page work may also ship a Kiwe Framework profile when it changes the shared design-token system without installing a DSA theme. A Framework profile uses `schema: "kiwe.framework-profile.v1"` and contains `settings.tokens` only: `tokens.enabled`, `tokens.profile_label`, official Kiwe universal `tokens.overrides`, and a complete `tokens.bricks_theme_style` lane with `enabled: true`, safe `id`, human `label`, and optional global-only style slots such as site background, palette, fonts, heading scale, links, radius, shadow, and spacing. Admins import/export this under `Kiwe > Framework`; AI staging clients may apply it with `kiwe.framework-profile.apply`, then separately push it to Bricks with `kiwe.framework.push-bricks`.

Staging-aware clients can inspect the target site and run the first controlled staging executor:

```text
GET  /wp-json/dsa/v1/ai/site-inspection?sampleLimit=12
POST /wp-json/dsa/v1/ai/staging/execute
POST /wp-json/dsa/v1/ai/stages/{stageId}/execute-staging
```

`/ai/site-inspection` is read-only and returns installed plugin inventory, active plugin status, safe Bricks option summaries, Bricks templates, pages/posts, custom post types, custom taxonomies, safe observed custom-field keys, and staging detection. It redacts secrets and does not expose raw Bricks page meta values.

`/ai/staging/execute` is intentionally narrow. It requires `confirmControlledStagingExecution: true` and `stagingSiteConfirmed: true`, refuses production-looking hosts unless explicitly overridden by the human, and supports only staging-safe operations such as:

- `wordpress.page.upsert`
- `wordpress.post.upsert`
- `bricks.template.create`
- `bricks.template.upsert`
- `bricks.settings.patch`
- `kiwe.framework-profile.apply`
- `kiwe.framework.push-bricks`
- `kiwe.theme-package.install-activate`
- `woocommerce.mutate`
- `woocommerce.product.upsert`
- `woocommerce.order.upsert`
- `woocommerce.settings.patch`
- `cart.run`
- `checkout.run`
- `auth.run`
- `bricks.raw-meta-write`

Page/post/template operations may include `html`, `bricksPasteHtml`, and optional `css`. The executor stores sanitized staging content and preserves safe preview CSS while refusing script-like payloads. `kiwe.framework-profile.apply` applies a sanitized Framework token profile to Kiwe settings only; `kiwe.framework.push-bricks` pushes the current Kiwe Framework design-token profile into Bricks as additive `kiwe-*` variables, Kiwe Universal palette, neutral Seam Class Vocabulary, and one safe global theme style while preserving non-Kiwe Bricks data. `bricks.settings.patch` is limited to known Bricks settings/options, scalar or simple nested payloads, safe path keys, and an internal patch hash log. It exists for staging checks such as Bricks import/converter switches or global setting probes; do not use it as a raw Bricks database writer.

WooCommerce, cart, checkout, auth, and raw Bricks operations require extra explicit flags:

- WooCommerce product/order/settings mutation: `confirmWooCommerceMutation: true`
- Cart/checkout/auth harnesses: `confirmRuntimeExecution: true`
- Auth test-user create/delete: `confirmAuthRuntime: true`
- Raw Bricks `_bricks*` meta writes: `confirmRawBricksJsonWrite: true`

The executor can create/update WooCommerce staging products, create/update staging orders, patch a controlled allow-list of WooCommerce settings, run server-side cart harness actions, validate checkout fields or create pending staging orders, create/delete Kiwe-marked test users, and write allowed Bricks meta keys with backup metadata. Bricks-ready HTML is still the preferred first path; raw `_bricks` JSON writes are for controlled staging adapter tests only.

For custom WordPress structures, use `/ai/site-graph` first. The graph includes `customContent.postTypes`, `customContent.taxonomies`, and `customContent.customFields` so AI can bind to real Pods/ACF/native custom content without guessing slugs or field handles. Field values are not exposed; use the keys/types/occurrence signals for planning only.

The legacy same-site admin REST path still exists for logged-in WordPress admin contexts at `GET /wp-json/dsa/v1/site-graph?sampleLimit=8`, but external AI tools should use `/wp-json/dsa/v1/ai/*` with a Kiwe AI key.

On WordPress 7+ with Abilities API available, Kiwe may also expose:

```text
dsa/get-site-graph
dsa/get-site-graph-data-schema
dsa/query-site-graph-data
dsa/get-securetrack-brief
dsa/get-internal-ai-context
dsa/run-internal-ai-advisor
dsa/enrich-internal-ai-advisor
dsa/get-companion-context
dsa/ask-companion
dsa/review-ai-output
dsa/start-studio-project
dsa/review-studio-output
dsa/get-bricks-ai-context
dsa/plan-bricks-ai-page
dsa/validate-bindings
dsa/validate-bricks-conversion
dsa/prepare-apply-plan
dsa/stage-apply-plan
```

## Preferred tool call

For capable MCP/tool clients, use the command router first when the user gives a phase command:

```json
{
  "tool": "kiwe_route_command",
  "arguments": {
    "command": "/rebuild /seamframework /usecompanion",
    "brief": "Paste the human's short phase request here.",
    "artifactSummary": "Briefly summarize the prior phase artifact when available.",
    "useCompanion": true
  }
}
```

If the user gives a broad one-shot request instead of a phased command, use:

```json
{
  "tool": "kiwe_start_project",
  "arguments": {
    "mode": "auto",
    "brief": "Paste the human's plain-language design brief here."
  }
}
```

Use `mode: "website"` for a normal WordPress/Bricks page, `mode: "theme"` for a Kiwe DSA/AppShell theme, and `mode: "combined"` when the output should include both website/page work and AppShell direction/settings.

For a dynamic binding revision after the handoff already exists, use:

```json
{
  "tool": "kiwe_start_dynamic_pass",
  "arguments": {
    "brief": "Use the human's plain-language dynamic binding request exactly.",
    "siteGraphSummary": "Summarize the supplied kiwe.site-graph.v1 JSON.",
    "currentHandoffSummary": "Summarize the current handoff being revised."
  }
}
```

## CLI fallback

If MCP/tool calling is unavailable but shell execution is allowed:

```bash
npm install --prefix kiwe-ai-toolkit
node kiwe-ai-toolkit/bin/kiwe.js start auto --brief "Paste the human brief here."
node kiwe-ai-toolkit/bin/kiwe.js dynamic-pass --brief "Paste the dynamic binding request here."
node kiwe-ai-toolkit/bin/kiwe.js bricks-conversion-context
node kiwe-ai-toolkit/tools/validate-framework-profile.cjs ./path/to/handoff --optional
node kiwe-ai-toolkit/tools/validate-bindings.cjs ./path/to/handoff --site-graph ./site-graph.json
node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs ./path/to/handoff --site-graph ./site-graph.json
node kiwe-ai-toolkit/tools/prepare-apply-plan.cjs ./path/to/handoff --site-graph ./site-graph.json
```

If shell execution is not allowed, use the browser AI path above.

For dynamic binding revisions, run `validate-bindings` when shell or MCP execution is available. For `/convert /bricks`, run `validate-bricks-conversion`. If execution is not available, do not claim it ran; instead self-check the binding/conversion plan against `dynamic-lite.md` and `bricks-conversion-lite.md` and report the limitation.

If the human is using the WordPress admin UI, they can upload the produced `bricks-bindings/kiwe-bindings.json` at `Kiwe > AI > AI connector and Site Graph` to get a live non-mutating validation report against the target site's current Site Graph. The same admin report also shows the dry-run apply-plan preview, lets the human download the reviewed apply-plan JSON, can stage it as a Kiwe-owned `kiwe.trusted-apply-stage.v1` review candidate, can run `kiwe.trusted-adapter-proof.v1`, can attach `kiwe.guarded-apply-authorization.v1`, can build `kiwe.pre-execution-gate.v1`, can build `kiwe.trusted-execution-preview.v1`, can attach `kiwe.final-apply-confirmation.v1`, can run `kiwe.fresh-sitegraph-revalidation.v1`, can build `kiwe.rollback-readiness-checkpoint.v1`, can attach `kiwe.target-resolution.v1`, can capture `kiwe.rollback-capture.v1`, can attach `kiwe.rendered-target-inspection.v1`, can build `kiwe.minimal-adapter-shell.v1`, can record `kiwe.final-save-approval.v1`, can build `kiwe.controlled-executor.v1`, can prepare `kiwe.bricks-controlled-adapter.v1`, and can build `kiwe.post-apply-verification.v1` as non-mutating proof artifacts without running CLI tools.

External API clients can run the same connector chain with a Kiwe AI key through `/wp-json/dsa/v1/ai/*`. These endpoints can read Site Graph context and write Kiwe internal staging/proof metadata, but they still do not save Bricks page content, publish WordPress changes, mutate WooCommerce data, or execute checkout/cart/auth behavior.

Direct mutation intent endpoints exist only as explicit locked surfaces so AI clients can discover the boundary instead of guessing:

```text
POST /wp-json/dsa/v1/ai/mutations/bricks-page-save
POST /wp-json/dsa/v1/ai/mutations/wordpress-publish
POST /wp-json/dsa/v1/ai/mutations/woocommerce
POST /wp-json/dsa/v1/ai/runtime/cart
POST /wp-json/dsa/v1/ai/runtime/checkout
POST /wp-json/dsa/v1/ai/runtime/auth
```

When called without the staging executor confirmation body, they return confirmation-required responses. With the same explicit flags used by `/ai/staging/execute`, they run through the controlled staging executor. AI keys still do not grant silent production mutation, shopper impersonation, payment execution, or unbounded database access.

`kiwe.controlled-executor.v1`, `kiwe.bricks-controlled-adapter.v1`, and `kiwe.post-apply-verification.v1` are still not saves. The executor records the future adapter interface. The adapter plan maps approved operation IDs to deterministic Bricks/Kiwe instructions. The post-apply proof selects the smallest future controlled run and proves rollback source/checks from the captured snapshot. These artifacts keep `actualApplyExecuted`/`actualSaveExecuted`, `actualRollbackExecuted`, and `mayExecuteMutationNow` false until a human starts a real staging-site controlled run.

On WordPress 7+ / MCP Adapter capable sites, Kiwe also exposes safe connector abilities for the same early chain: `dsa/get-site-graph`, `dsa/get-site-graph-data-schema`, `dsa/query-site-graph-data`, `dsa/get-securetrack-brief`, `dsa/get-internal-ai-context`, `dsa/run-internal-ai-advisor`, `dsa/enrich-internal-ai-advisor`, `dsa/get-companion-context`, `dsa/ask-companion`, `dsa/review-ai-output`, `dsa/start-studio-project`, `dsa/review-studio-output`, `dsa/get-bricks-ai-context`, `dsa/plan-bricks-ai-page`, `dsa/validate-bindings`, `dsa/validate-bricks-conversion`, `dsa/prepare-apply-plan`, and `dsa/stage-apply-plan`. These abilities do not save Bricks/page content or mutate security enforcement; `dsa/run-internal-ai-advisor` is deterministic/read-only, `dsa/enrich-internal-ai-advisor` prepares a model-optional read-only summary/envelope, `dsa/start-studio-project` returns a token-saving Studio packet, `dsa/get-bricks-ai-context` and `dsa/plan-bricks-ai-page` return read-only Bricks-native planning packets, and `dsa/stage-apply-plan` writes only a Kiwe internal review queue record.

For apply-path requests, run `prepare-apply-plan` only after `validate-bindings` passes. The apply plan is dry-run and non-mutating. Do not claim WordPress, Bricks, WooCommerce, or Kiwe were changed unless a future trusted adapter actually performs the mutation with admin approval.

## Human prompt should be short

The human should not need to prompt-engineer Kiwe. They should only provide the repo/toolkit pointer and the design intent. Do not expect humans to mention output folders, Bricks artifacts, AppShell validator rules, screen eligibility, overflow rules, or Kiwe authority boundaries; the toolkit response supplies those.

Good human prompts:

```text
Use the Kiwe AI Toolkit. Read only:
https://raw.githubusercontent.com/Museintel/kiwe/main/KIWE-AI.md

Create a Netflix-like ultra-modern news website for Indian startups and businesses, with its Kiwe AppShell included.
```

```text
Use the Kiwe AI Toolkit. Read only:
https://raw.githubusercontent.com/Museintel/kiwe/main/KIWE-AI.md

Create a quiet luxury DSA AppShell theme.
```

```text
Use the Kiwe AI Toolkit. Read only:
https://raw.githubusercontent.com/Museintel/kiwe/main/KIWE-AI.md

Create a conversion-focused product landing page.
```

The AI must translate the plain-language request into the correct toolkit mode:

```json
{
  "tool": "kiwe_start_project",
  "arguments": {
    "mode": "auto",
    "brief": "Use the human's plain-language design brief exactly."
  }
}
```

The toolkit response carries the detailed output format, Bricks handoff rules, Seam framework boundaries, AppShell screen rules, preview requirements, responsive overflow rules, and validation expectations. The Kiwe AppShell is runtime chrome around the page, not part of the Bricks page itself.
