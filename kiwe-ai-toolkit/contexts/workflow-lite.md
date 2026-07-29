# Kiwe AI workflow-lite context

Use this file when the human wants better AI output quality, fewer correction loops, or a slash-command style Kiwe workflow.

Do not clone, crawl, or read the full Kiwe repository. The goal is to route the AI into the smallest useful phase instead of asking one model turn to be maximally creative, framework-compliant, Bricks-aware, AppShell-safe, and audit-ready all at once.

Fast machine-readable command router:

```text
https://raw.githubusercontent.com/Museintel/kiwe/main/kiwe-ai-toolkit/command-manifest.json
```

Use the manifest first when available. It is intentionally smaller than this prose file and tells you the narrow context files, allowed outputs, forbidden outputs, and compact final response shape for each slash command.

## Core principle

Creativity and contract compliance are different jobs.

Run them as separate phases:

1. Pure creative draft.
2. Seam rebuild.
3. Seam audit.
4. Framework profile for Kiwe > Framework import/push.
4a. Optional standalone Bricks Theme Styles JSON when the human asks for `/brickstheme`.
5. DSA AppShell theme.
6. DSA audit.
7. Combined assembly when both lanes are approved.
8. Dynamic WordPress / Bricks / WooCommerce binding after the visual handoff passes.
9. Bricks conversion package after dynamic intent is approved.
10. Bricks conversion audit.
11. Accessibility plan/audit/fix for WCAG contrast, native light/dark mode, Kiwe/Seam token pairs, and visible text containment.
12. Controlled staging apply only when a trusted Kiwe site executor is explicitly authorized.

This pipeline is preferred over one giant `combined` prompt for serious work.

## Human-facing command vocabulary

Humans should be able to write short commands. The toolkit supplies the rules.

Terminal-style entry pattern:

```text
explore: https://github.com/Museintel/kiwe
/list
```

`explore:` is not a command and is not permission to browse the repository. It is a location pointer to the Kiwe toolkit. Read the public entrypoint or this workflow file, execute the slash command that follows it, and stop when that command tells you to stop. GitHub URL path segments must never be interpreted as Kiwe slash commands.

Canonical creation verb: `/create`.

Do not teach mixed verbs such as `/build` for Kiwe handoff phases. If an older model or human writes `/build`, treat it as a legacy alias internally and answer back with the canonical `/create` command name.

Canonical preview commands:

```text
/create /preview /dsatheme
/create /preview /combined
```

`/create /preview /dsatheme` is only for the AppShell theme preview lane. `/create /preview /combined` is only for the primary combined preview lane. Neither command creates Bricks JSON, and neither preview is valid input for `/convert /bricks`.

Canonical discovery and repair commands:

```text
/list
/fix
```

`/list` returns the supported command vocabulary and stops. `/fix` repairs an existing failed artifact lane; it does not start over or create a new unrelated package.

Unless the human includes `/document`, command output should be lean: emit only the requested artifact file(s), then answer with compact `PASS` / `FAIL` / `WARN`, files changed, and remaining warnings. Do not add notes, reports, ZIPs, duplicate previews, or long explanatory essays.

Canonical Site Graph command:

```text
/usesitegraph
```

Legacy `/dynamic /sitegraph` and shorthand `/sitegraph` may be accepted internally, but user-facing output should say `/usesitegraph`.

## Command gate / no-waste boundary

Before doing real work for any slash command, validate the command cheaply.

Tool-capable clients should call:

```text
kiwe_diagnose_command
```

CLI-capable clients can run:

```bash
node kiwe-ai-toolkit/bin/kiwe.js diagnose --command "/convert /bricks" --artifact-summary "website/bricks-paste.html exists; framework/kiwe-framework-profile.json exists"
```

The diagnostic result uses `schema: "kiwe.command-diagnostic.v1"` and returns one of:

- `ok`: continue with the selected phase;
- `rejected`: no such command or forbidden lane combination;
- `needs_input`: command is real, but required artifact/context/authority is missing;
- `noop`: command is real but useless because the requested output already exists or is the same artifact.

If `stop: true`, stop the flow and answer the human with the diagnostic. Do not continue into generation, conversion, audit, dynamic binding, or staging work.

Examples:

- `/buid /preview /brickstheme` -> `rejected`, `unknown_command_token`; suggest canonical `/create` commands.
- `/create /preview /brickstheme` -> `rejected`, `unsupported_preview_target`; Framework/Bricks theme profiles are token JSON and have no separate preview lane.
- `/create /preview /website` when `website/bricks-paste.html` already exists -> `noop`, `website_preview_already_exists`; the page artifact is already the preview.
- `/convert /bricks` without `website/bricks-paste.html` -> `needs_input`, `bricks_convert_missing_page_source`.
- `/convert /bricks` with `website/bricks-paste.html` but no `framework/kiwe-framework-profile.json`, `bricks-theme-style.json`, or human confirmation that Kiwe > Framework is already pushed -> `needs_input`, `bricks_convert_missing_framework_profile`.
- `/convert /bricks` against `combined-preview` or `appshell-theme` -> `rejected`, `bricks_convert_forbidden_source_in_command`.
- `/audit /bricksconversion` without `bricks-template/*-template-upload.json` or `bricks-conversion/kiwe-bricks-conversion.json` -> `needs_input`, `bricks_audit_missing_conversion_artifact`.
- `/usesitegraph` without Site Graph/API/export context -> `needs_input`, `dynamic_missing_site_graph`.
- `/usesitegraph /replacepreviewdata` without an existing handoff -> `needs_input`, `sitegraph_replacepreview_missing_artifact`.
- `/fix` without an existing artifact or audit output -> `needs_input`, `fix_missing_artifact`.
- `/apply /staging` without explicit staging confirmation/mutation authority -> `needs_input`, `staging_missing_explicit_authority`.

## Optional `/usecompanion` flag

`/usecompanion` can be appended to any workflow command:

```text
/rebuild /seamframework /usecompanion
/create /frameworkprofile /usecompanion
/create /brickstheme /usecompanion
/audit /dsatheme /usecompanion
/create /preview /dsatheme /usecompanion
/create /preview /combined /usecompanion
/usesitegraph /usecompanion
/convert /bricks /usecompanion
/audit /bricksconversion /usecompanion
/create /accessibility /usecompanion
/audit /accessibility /usecompanion
```

This flag means: use Kiwe Companion if it is available, then continue the selected phase. It must never become a blocker.

If `/nonai` appears with `/usesitegraph`, it overrides `/usecompanion`: use the AI-less Site Graph Data/export lane only.

If `KIWE_REST_BASE` and `KIWE_AI_KEY` are available, make one bounded Companion attempt. If the key is missing, Companion is disabled, the route fails, rate-limits, times out, returns unclear data, or HTTP/tool access is not available, ignore `/usecompanion` and run the command before it normally.

Companion is not a wandering creative AI. It is a compact Kiwe contract oracle and deterministic reviewer:

- mode/phase cards;
- rule IDs;
- context hashes;
- Site Graph hashes;
- prior audit-failure fingerprints;
- `mustFix` / `shouldFix` / `passed` maps for file reviews;
- safe next-action hints.

Do not ask Companion to read or return the whole plugin line by line. Do not upload secrets, raw SecureTrack logs, customer data, or full repository files unless the route explicitly asks for the generated handoff file map inside its byte budget. The token-saving goal is to fetch the smallest route-specific truth, not to create another giant context window.

For generation, rebuild, create, assemble, dynamic, and staging-planning phases, prefer:

```text
GET|POST /wp-json/dsa/v1/ai/companion/context
POST     /wp-json/dsa/v1/ai/companion/ask
```

Use payload fields such as `mode`, `phase`, `command`, `brief`, `artifactSummary`, and `sampleLimit` when the client can send them. Unknown fields are advisory and should not be treated as production writes.

For audit and revision phases, prefer:

```text
POST /wp-json/dsa/v1/ai/audit-companion/review
```

Submit the actual generated file map, fix every `mustFix`, then rerun once if practical. If the audit route is unavailable, perform the normal toolkit audit from this file and the relevant mode/audit context.

When `/usecompanion` appears, the final response should include a compact `COMPANION-TRACE`:

- routes attempted;
- whether each route succeeded, failed, or was skipped;
- contextHash / siteGraphHash when supplied;
- number of cards or findings used;
- fallback reason, if any;
- confirmation that Companion did not replace the selected Kiwe phase.

### `/list`

Use when the human wants the available Kiwe command language before choosing a phase.

- Return the command list, aliases, required inputs, expected outputs, and hard boundaries.
- Do not start generation, audit, Site Graph, Bricks conversion, DSA theme work, or staging.
- Tool-capable clients should call `kiwe_list_commands` when available.
- CLI-capable clients can run:

```bash
node kiwe-ai-toolkit/bin/kiwe.js list
```

### `/fix`

Use when an existing output failed audit, followed the wrong file shape, mixed lanes, or needs correction.

`/fix` repairs the current artifact lane. It must not restart the creative process unless the human explicitly asks.

Required input:

- the generated artifact folder/file map or actual files;
- the audit failure, error message, or short description of what failed.

Rules:

- Revise actual files, not only explanations.
- Keep only files required by the current lane unless the human explicitly requested extras.
- Do not create a new unrelated package to hide the failed one.
- If the artifact is a Bricks conversion/template upload, require `bricks-template/*-template-upload.json` or `bricks-conversion/kiwe-bricks-conversion.json`.
- If the artifact is a Seam rebuild, keep `website/bricks-paste.html` as the single page preview/import artifact.
- If the artifact is a DSA theme, keep AppShell theme CSS separate from Bricks/page CSS.
- If the artifact is combined, keep `website/`, `appshell-theme/`, and `combined-preview/` separate.
- Preserve Site Graph/dynamic intent instead of converting sampled preview data into production hardcoding.

If the correct lane is unclear, diagnose first and ask for the missing artifact map.

### `/document`

Use only when the human explicitly asks for notes, handoff documentation, mapping explanations, or a written summary for an already-created artifact.

Required input:

- the artifact folder/file map or actual files to document.

Rules:

- Do not create or revise the website/page, DSA theme, Bricks conversion, accessibility plan, or combined preview unless the human also supplied a matching create/fix command.
- Keep documentation compact and lane-specific.
- For a Seam rebuild, create `website/bricks-notes.md` only when this command is present.
- For a Bricks conversion, document conversion assumptions, manual-review items, native element mapping, dynamic intent, and any unsupported source features.
- For a DSA theme, document installed theme package identity, supported screen coverage, preview-only content, and geometry boundaries.
- For combined handoffs, document how the already-created page, AppShell theme, preview, Site Graph/dynamic intent, and Bricks artifacts connect.

Documentation is opt-in everywhere. If `/document` is absent, produce only the canonical artifact file(s) for the selected command and do not add README files, notes, reports, duplicate previews, ZIPs, or explanation files.

### `/ideate /webdraft`

Use when the human wants maximum visual creativity.

- Do not mention Kiwe, DSA, Seam, Bricks, WordPress, or WooCommerce unless the human independently asked for them.
- Produce a pure HTML/CSS/JS website/page draft.
- Optimize for concept, visual hierarchy, motion idea, editorial/commercial personality, and layout invention.
- Output can be a single `index.html` with embedded CSS/JS or a simple preview folder.
- Do not try to make it import-ready.

This phase exists because starting with constraints can flatten creativity.

### `/rebuild /seamframework`

Use after the human likes a pure draft and wants it rebuilt with Seam.

- Input is the approved creative draft.
- Preserve the visual thesis, content rhythm, layout intent, and art direction.
- Rebuild into semantic HTML using official Seam roles/classes/tokens.
- Seam is headless: do not make `data-role` invent visual components.
- Project-specific ideas go into ordinary classes or `data-project-role`, not custom `data-role`.
- Use the Seam capability attribute library when the draft already has matching UI intent: save/wishlist/bookmark, notification permission, light/dark toggle, AppShell launcher, menu/table-of-contents section, or dynamic/query sample rail.
- Preserve the visible UI and add the smallest live Kiwe attribute. Do not replace good design with generic controls merely because an attribute exists.
- Keep production behavior minimal and framework-safe.
- Do not recreate Kiwe-owned capability behavior in page JavaScript when an attribute exists.
- Do not add DSA AppShell markup.
- Do not add Bricks JSON.

Expected output:

```text
website/
  bricks-paste.html
```

`website/bricks-paste.html` is both the preview and the Bricks paste/import artifact.
Do not emit `website/bricks-notes.md`, README files, reports, or additional documentation in this phase unless the command also includes `/document` or the human asks for documentation.

### `/audit /seamframework`

Use after a Seam rebuild.

Audit for:

- official Seam roles only;
- useful Seam Class Vocabulary usage;
- no custom `data-role` values;
- no duplicated app capabilities such as cart/auth/search/save/AI authority;
- save/wishlist/bookmark controls use `data-kiwe-save` when the UI intent exists;
- notification permission CTAs use `data-kiwe-notifications` and do not prompt without a visitor click;
- light/dark controls outside the dock use `data-kiwe-theme-toggle`;
- page/header DSA openers use canonical `data-dsa-open-module`;
- menu context uses real semantic sections/headings, not hidden duplicate anchors;
- sample rails meant for later live data use `data-kiwe-query-template` / `data-kiwe-binding`;
- no frontend scraping dependency;
- Bricks-friendly HTML/CSS;
- readable responsive spacing;
- no hardcoded production behavior that belongs to WordPress, Bricks, WooCommerce, or Kiwe;
- no horizontal viewport overflow except intentional rails;

### `/create /accessibility`

Use after an artifact exists and the human wants light/dark and contrast support. This is not a creative redesign phase.

Required input:

- existing page/theme/combined/framework/conversion files or a clear artifact map.

Expected output:

```text
accessibility/
  kiwe-accessibility-plan.json
  ACCESSIBILITY-NOTES.md # optional only when /document is requested
```

Rules:

- Cover color contrast, native light/dark mode, and visible text containment. Full font-size/readability preference work is a later lane, but clipped or unreadable text is in scope now.
- Inspect the actual visual surfaces and text-bearing components: chips, badges, pills, buttons, cards, stats, product labels, rails, dock controls, and DSA screen/sheet copy.
- Use official Kiwe/Seam color tokens first: `--kiwe-color-surface`, `--kiwe-color-surface-raised`, `--kiwe-color-text`, `--kiwe-color-text-muted`, `--kiwe-color-text-inverse`, `--kiwe-color-brand`, `--kiwe-color-accent`, state colors, and borders.
- For Bricks targets, align with Bricks global theme-style lanes such as `siteBackground`, `colorPrimary`, `colorSecondary`, `colorLight`, `colorDark`, and `colorMuted`.
- Add or preserve native theme state: `data-kiwe-theme`, `data-kiwe-theme-toggle`, or a clearly mapped standalone `data-theme`.
- Do not create a new duplicate preview; revise the existing preview/page/theme lane if dark-mode proof is missing.

### `/audit /accessibility`

Use after `/create /accessibility` or whenever a page/theme/combined output has visible contrast problems.

Audit for:

- `accessibility/kiwe-accessibility-plan.json` exists and uses `schema: "kiwe.accessibility-plan.v1"`;
- both light and dark modes are covered;
- literal low-contrast pairs fail, including white-on-white, light-on-light, black-on-black, and dark-on-dark pills/cards/buttons;
- critical text-bearing titles, labels, pills, chips, buttons, tabs, prices, stats, and card headings are not clipped, hidden, nowrap-ellipsized, or line-clamped inside constrained boxes;
- bento/card/product rail layouts do not cut visible text at desktop, tablet, mobile, or narrow widths;
- gradients/images behind text have a solid fallback token or manual-review note;
- private project color variables are mapped back to Kiwe token pairs;
- Bricks outputs use Kiwe/Bricks theme-style color alignment instead of an isolated palette;
- DSA themes do not hide contrast fixes in preview-only CSS;
- production artifacts preserve dynamic tags/query loops and do not hardcode sampled preview data.

Tool-capable clients should run:

```bash
node kiwe-ai-toolkit/tools/validate-accessibility.cjs <handoff-or-accessibility-dir>
```

If tools are available, run the relevant Kiwe validators. If tools are not available, revise the actual files manually and report what changed.

### `/create /frameworkprofile`

Use after the Seam page direction is approved and the human wants the site personality turned into a Kiwe > Framework import profile.

The admin imports this file in Kiwe > Framework and pushes variables, color palette, global classes, and Bricks theme-style data from there. This is the preferred setup path for most users.

Expected output:

```text
framework/
  kiwe-framework-profile.json
```

The profile must use:

```json
{
  "schema": "kiwe.framework-profile.v1",
  "settings": {
    "tokens": {
      "enabled": true,
      "profile_label": "Human readable name",
      "overrides": {},
      "bricks_theme_style": {
        "enabled": true,
        "id": "kiwe-human-readable-name",
        "label": "Human Readable Name Bricks Theme Style",
        "siteBackground": "#f6f8f7",
        "colorPrimary": "#d6006f",
        "colorSecondary": "#24c6a1",
        "colorLight": "#ffffff",
        "colorDark": "#1f2933",
        "colorMuted": "#64717d",
        "fontDisplay": "Inter, system-ui, sans-serif",
        "fontBody": "Inter, system-ui, sans-serif",
        "typeH1": "clamp(52px, 5vw + 36px, 108px)"
      }
    }
  }
}
```

Rules:

- Use official Kiwe universal token names only, such as `color-brand`, `color-accent`, `color-surface`, `color-text`, `font-display`, `font-body`, `type-h1`, `space-md`, `radius-lg`, and `shadow-md`.
- `bricks_theme_style` is required for a complete Framework profile. It must set `enabled: true`, a safe `id`, and a human `label`.
- `bricks_theme_style` may carry safe global style slots only: site background, global colors, typography, links, radius, shadow, and spacing. Kiwe normalizes those slots back into universal tokens and then generates the native Bricks Theme Style during the Kiwe > Framework push.
- `/audit /frameworkprofile` must not rely on the human to list missing live variables. It must independently verify the core live token foundation needed by Seam and Bricks after push: `color-brand`, `color-accent`, `color-surface`, `color-surface-raised`, `color-text`, `color-text-muted`, `color-border`, `font-display`, `font-body`, `type-h1`, `type-body`, `space-md`, `radius-lg`, and `shadow-md`.
- The profile may cover those tokens either directly in `settings.tokens.overrides` or through mapped `bricks_theme_style` slots such as `colorPrimary`, `colorSecondary`, `siteBackground`, `colorDark`, `colorMuted`, `colorBorder`, `fontDisplay`, `fontBody`, `typeH1`, `typeBody`, `spaceMd`, `radiusLg`, and `shadowMd`.
- Use official Kiwe variables implied by token names, for example `color-brand` -> `--kiwe-color-brand` and `space-md` -> `--kiwe-space-md`. Do not invent non-canonical variables such as `--kiwe-color-primary` or `--seam-color-primary`.
- Do not put AppShell dock/sheet/screen settings, products, posts, raw Bricks JSON, WooCommerce behavior, or runtime JS here.
- Do not output `FRAMEWORK-NOTES.md`, README files, reports, Bricks template JSON, or AppShell theme packages unless `/document` is explicitly present.

### `/audit /frameworkprofile`

Audit for:

- `schema: "kiwe.framework-profile.v1"`;
- `settings.tokens` only;
- official Kiwe token names only;
- no raw `--kiwe-*` or private `--dsa-runtime-token-*` keys;
- complete core token coverage for the live Seam/Bricks foundation listed above;
- complete `bricks_theme_style.enabled`, `bricks_theme_style.id`, and `bricks_theme_style.label`;
- no AppShell settings;
- no Bricks element-level styling;

Do not treat `/audit /frameworkprofile` as an alias of `/audit /brickstheme`. A Framework profile is a Kiwe import file and may include safe `settings.tokens.bricks_theme_style` global slots such as `siteBackground`, `colorPrimary`, `colorSecondary`, `colorLight`, `colorDark`, `colorMuted`, `colorBorder`, `fontDisplay`, `fontBody`, `typeH1`, `typeBody`, `spaceMd`, `radiusLg`, and `shadowMd`. These slots are valid in Framework profiles because Kiwe normalizes them into official universal tokens and native Bricks Theme Style data during Kiwe > Framework push. Do not strip them down to only `enabled`, `id`, and `label`.

### `/create /brickstheme`

Use only when the human asks for the standalone Bricks Theme Styles import file instead of the Kiwe > Framework profile.

Expected output:

```text
bricks-theme-style.json
```

The root shape is:

```json
{
  "id": "optional-safe-id",
  "label": "Human readable Bricks style name",
  "settings": {}
}
```

Rules:

- This is native Bricks Theme Styles JSON for the Bricks visual editor.
- Root `id` is optional for Bricks-export compatibility; if omitted, Bricks/Kiwe can generate one.
- It is not a Kiwe Framework profile, not a Bricks page/template upload, and not a DSA/AppShell theme package.
- Do not include `schema`, `theme-package.json`, `content`, `header`, `footer`, `globalClasses`, `global_classes`, AppShell screen/dock selectors, WooCommerce runtime data, cart/checkout/auth behavior, or docs unless `/document` is present.
- Prefer Kiwe/Seam token variables and Bricks global color/theme slots where practical.

### `/audit /brickstheme`

Audit for:

- exactly one `bricks-theme-style.json`;
- root `label` and `settings`;
- no Framework profile wrapper;
- no Bricks template/page JSON;
- no AppShell/DSA theme selectors or runtime authority;
- global-only style scope: site background, colors, typography, and links.
- no product/content/runtime authority.

If tools are available, run:

```bash
node kiwe-ai-toolkit/tools/validate-framework-profile.cjs /path/to/handoff-or-profile
```

### `/create /dsatheme`

Use when the human wants a Kiwe DSA/AppShell theme. This can happen after the website direction is approved, or independently.

The AI may be creative here. The constraint is not visual sameness; the constraint is authority.

- Create a distinctive AppShell look for dock, sheet/classic screens, screen interiors, states, badges, action rows, rails, forms, and empty states.
- Use live Kiwe AppShell selectors and `data-dsa-part` hooks.
- Style every registered screen listed in the theme manifest, not only broad panel colors.
- Theme settings belong inside `theme-package.json`.
- Importable theme CSS must not own Geometry Engine placement or lifecycle.
- No JS, PHP, remote assets, service workers, cart/checkout/auth/search/save/AI authority, Bricks templates, or WordPress mutations.

Expected output:

```text
appshell-theme/
  README.md
  import/
    [theme-id]/
      theme-package.json
      theme.json
      css/
        theme.css
```

Optional technical fixture:

```text
appshell-theme/
  preview/
    index.html
    PLACEHOLDERS.md
```

### `/create /preview /dsatheme`

Use when the AppShell theme import package already exists or is being reviewed and the human needs a focused DSA preview proof.

Create or revise only:

```text
appshell-theme/
  preview/
    index.html
    PLACEHOLDERS.md
```

Rules:

- The preview must load or faithfully apply the importable `appshell-theme/import/[theme-id]/css/theme.css`.
- Prove live-like DSA roots, documented screen/sheet internals, dock modes, navbar mode, orientation, shape, light/dark, narrow widths, and every registered screen.
- Mark all mock content as preview-only.
- Do not create or convert a Bricks page.
- Do not use this preview as `/convert /bricks` source.

### `/audit /dsatheme`

Use after DSA theme creation.

Audit for:

- valid `theme.json`;
- valid `theme-package.json`;
- `settings.tokens` for live-intended palette/typography/personality;
- `settings.screens` for live-intended screen copy;
- live selectors instead of preview-only fixture selectors;
- no protected Geometry Engine ownership;
- no anonymous raw CSS literals in importable `theme.css`;
- every listed screen has distinctive live styling;
- FBT is a readable horizontal rail;
- Search form wrapper is not accidentally styled as an extra container;
- no blank custom dock icons;
- focus item styling is independent from active/open state;
- repeated dock/header launches do not stack duplicate sheets.

If tools are available, run:

```bash
node kiwe-ai-toolkit/tools/validate-output.cjs /path/to/handoff --mode theme
node kiwe-ai-toolkit/tools/audit-output.cjs /path/to/handoff
```

### `/assemble /combined`

Use only after the website/Seam lane and DSA theme lane are both approved or mostly approved.

Do not redesign from scratch unless the human asks. Assemble the already-approved page and AppShell theme into one combined handoff.

Expected output:

```text
combined-kiwe-handoff/
  README.md
  combined-preview/
    index.html
    assets/
      combined-preview.css
      combined-preview.js
  website/
    bricks-paste.html
    bricks-notes.md # optional only when /document is requested
  appshell-theme/
    README.md
    import/
      [theme-id]/
        theme-package.json
        theme.json
        css/
          theme.css
```

Combined mode has one primary human preview: `combined-preview/index.html`.

That preview must show the website/page behind the Kiwe AppShell and include variation controls for Geometry Engine profiles, narrow widths, Sheet/Classic, dock/navbar presentation, orientation, shape, light/dark, and representative screen switching.

### `/create /preview /combined`

Use when the website lane and AppShell theme lane exist and the human needs one proof that they work together.

Create or revise only:

```text
combined-preview/
  index.html
  assets/
    combined-preview.css
    combined-preview.js
```

Rules:

- The preview must show the page behind the Kiwe AppShell.
- Use the real page lane as the backdrop/reference, but keep the preview itself preview-only.
- Include variation controls for desktop, tablet, mobile, narrow widths, Sheet/Classic, full compact dock, split compact dock, Navigation Bar, horizontal/vertical orientation, pill/rounded-box/square shape, light/dark, and screen switching.
- Keep page/header launchers live in the preview.
- Do not create Bricks JSON.
- Do not use `combined-preview/index.html` as `/convert /bricks` source.

### `/audit /combined`

Use after combined assembly.

Audit all three lanes:

- Website/page lane.
- AppShell theme lane.
- Combined preview lane.

The combined preview must be one visual proof of the page plus AppShell together. `website/bricks-paste.html` remains page-only. `appshell-theme/import/.../theme.css` remains AppShell-only.

If a target-site Kiwe key is available, submit the actual file map to:

```text
POST /wp-json/dsa/v1/ai/audit-companion/review
```

Fix every `mustFix` item, then rerun the audit.

### `/usesitegraph`

Use only after the visual handoff passes.

Legacy alias: `/dynamic /sitegraph`.

Purpose:

- Convert approved static rails/cards/buttons into a dynamic binding plan using real WordPress, Bricks, WooCommerce, and Kiwe Site Graph facts.
- Use query loops, dynamic tags, conditions, interactions, and Kiwe launchers where the target Site Graph/Bricks context supports them.
- Do not guess product categories, page slugs, post types, custom fields, dynamic tags, or Bricks query-loop object types.
- Do not mutate the site.

Accepted target-site truth sources:

1. `KIWE_REST_BASE` plus `KIWE_AI_KEY` with allowed Site Graph/Bricks scopes.
2. Exported `kiwe.site-graph.v1` JSON.
3. AI-less/public read-only Site Graph Data routes.

If no Site Graph/API/export is available, stop and ask for it. Do not scrape the frontend as a fallback.

Variants:

- `/usesitegraph /replacepreviewdata`: replace preview-only sample content with real Site Graph samples, while keeping production/import artifacts dynamic through Bricks tags, query-loop intent, or bindings. Alias: `/usesitegraph /replacepreview`.
- `/usesitegraph /websitename`: derive site name, logo, menu labels, identity, and broad tone from Site Graph identity/menu data only.
- `/usesitegraph /nonai`: force the AI-less/read-only Site Graph Data or exported JSON lane. Do not call Companion, native AI, Advisor, Studio, or model-backed routes.

Expected output:

```text
bricks-bindings/
  kiwe-bindings.json
```

Do not emit `BINDING-NOTES.md`, README files, reports, or extra docs unless the command also includes `/document` or the human explicitly asks for documentation.

If tools are available:

```bash
node kiwe-ai-toolkit/tools/validate-bindings.cjs /path/to/handoff --site-graph /path/to/site-graph.json
node kiwe-ai-toolkit/tools/prepare-apply-plan.cjs /path/to/handoff --site-graph /path/to/site-graph.json
```

`prepare-apply-plan` is dry-run planning, not mutation.

### `/convert /bricks`

Use only after the website/page visual artifact passes, and only after a Framework profile or Bricks theme style exists or the human confirms Kiwe > Framework/Bricks Theme Styles are already pushed. When the page should use live WordPress/Bricks/WooCommerce data, also run after `/usesitegraph` has mapped that intent.

Purpose:

- Convert the approved `website/bricks-paste.html` artifact into the Bricks-native JSON a human can upload in Bricks > My Templates.
- `/convert /bricks` is the only public Bricks conversion command.
- Preserve the approved layout, hierarchy, classes, IDs, ARIA, official Seam roles/classes, `data-seam-*`, `data-project-role`, and canonical Kiwe launchers such as `data-dsa-open-module`.
- Prefer Bricks 2.4 native HTML/CSS-to-Bricks conversion when the target exposes it.
- Carry Kiwe's no-loss proof for query loops, dynamic tags, conditions, interactions, unsupported features, and manual-review gates inside the Bricks upload file when practical.
- Do not mutate WordPress, Bricks, WooCommerce, cart, checkout, or auth.
- Do not convert `combined-preview`, `appshell-theme`, DSA/AppShell theme packages, screen/sheet/dock/navbar markup, `theme-package.json`, or `css/theme.css`.
- Do not create the missing Framework profile inside this command. If no `framework/kiwe-framework-profile.json`, `bricks-theme-style.json`, or explicit "already pushed" confirmation exists, stop and ask for `/create /frameworkprofile` first.

Expected output:

```text
bricks-template/
  [page-name]-template-upload.json
```

Do not emit `BRICKS-CONVERSION-NOTES.md`, `FRAMEWORK-NOTES.md`, README files, reports, ZIPs, duplicate previews, or other docs unless `/document` is explicitly present.

The upload JSON must match Bricks' own template import shape: top-level non-empty `title`, `templateType`, and one non-empty `content`, `header`, or `footer` array. A homepage body should use `title: "Home"` and `templateType: "content"`. Use Bricks' native `global_classes` key for importable global class dependencies; copied-elements `globalClasses` alone is not enough for Bricks My Templates upload. If Kiwe no-loss proof is included, place it in a top-level `kiwe` metadata object so Bricks can ignore it while Kiwe validators can inspect it.

Do not give the human `bricks-conversion/kiwe-bricks-conversion.json` as the file to upload. That object is an optional Kiwe audit/executor envelope, not a Bricks template. If uploaded to Bricks, it imports as `(no title)` and fails insertion with "This template has no data" because it has `elements` instead of `content/header/footer`.

Native-first does not mean hardcoded-native. Template-upload handoffs must not depend on `pageSettings.customCss` for ordinary design, because inserted templates can lose that page CSS or be controlled by stale target-page CSS. Put typography, layout, responsive grid/flex, backgrounds, borders, shadows, sizing, spacing, and alignment into Bricks element settings/global classes/global variables first. Those native Bricks values must still consume the Kiwe/Seam Framework token layer: use `var(--kiwe-*)`, `var(--seam-*)`, declared project variables, or real tokenized `clamp(...)` values for spacing, sizing, radius, type, shadow, transform offsets, responsive layout controls, and every component color/paint value. Do not emit hardcoded values such as `padding: 28px`, `_border.radius: 24px`, `_heightMin: 390px`, `_typography.font-size: 2.35rem`, or `_rowGap: 20px` in element settings or `global_classes`; map them to Framework tokens/project variables backed by `kiwe-framework-profile.json`. Do not emit direct component colors such as `color: #fff`, `_background.color.raw: #8deae5`, `linear-gradient(#201b18, #514238)`, `rgba(255,255,255,.11)`, or `--pack-bg: #f5b942`; map them to official Kiwe/Seam variables or declared project variables. Literal colors are allowed only at Framework/global variable definition layer or as fallbacks inside `var(...)`. No-op clamps such as `clamp(22px, 22px, 22px)` are disguised literals and must fail. Token definitions may use plain values when their behavior role is a named `fixed-primitive`, `geometry-input`, `content-limit`, or `responsive-guard`; Bricks/page output still must consume the named token rather than copy the plain value. Tokenization fallback ladder: first use an official Kiwe/Seam token when meaning and property domain match; second use a declared project token for stable art direction; third use a real fluid clamp only when the source proves different responsive values for the same property. Kiwe clamp math is `slope = (maxValue - minValue) / (maxViewport - minViewport) * 100`, `intercept = minValue - (slope / 100 * minViewport)`, then `clamp(minValue, calc(intercept + slope * 1vw), maxValue)`. CLI/MCP-capable tools may run `kiwe fluid-clamp --min 220px --max 390px --min-vw 478 --max-vw 1440`. `fidelity.responsiveIntent` or embedded `kiwe.fidelity.responsiveIntent` is required whenever the conversion contains bento/campaign/editorial grids, CSS grid placement, media-query layout behavior, or Bricks breakpoint layout overrides. Bricks 2.4 responsive controls are stored as `controlKey:breakpoint`, including `_direction:<breakpoint>`, grid controls, `_cssCustom:<breakpoint>`, and custom site breakpoint keys. Bricks layout elements (`container`, `div`, `section`, `block`) use `_direction` / `_direction:<breakpoint>` for flex direction; `_flexDirection` is only for non-nestable elements. CSS-heavy or mappable-CSS-heavy conversions must include native style proof showing ordinary visual rules became editable Bricks controls/global classes/global variables before any custom-CSS exceptions; common layout/visual properties should be emitted as controls such as `_display`, `_direction`, `_justifyContent`, `_alignItems`, `_flexWrap`, `_columnGap`, `_rowGap`, `_gridTemplateColumns`, `_gridItemColumnSpan`, `_typography`, `_background`, `_gradient`, `_border`, `_boxShadow`, `_transform`, `_cssFilters`, and `_cssTransition`.

`source.html` must point to `website/bricks-paste.html`.

Use `/wp-json/dsa/v1/ai/bricks/context` or MCP `kiwe_get_bricks_conversion_context` when available. That context describes real Bricks elements, query loops, dynamic tags, conditions, interactions, and the Kiwe conversion package.

### `/audit /bricksconversion`

Use after `/convert /bricks`.

Audit for:

- a native `bricks-template/*-template-upload.json` exists with non-empty `title`, `templateType`, and `content/header/footer`, or an older `bricks-conversion/kiwe-bricks-conversion.json` exists and points to that native template through `target.templateExportPath`;
- documentation/report files exist only if `/document` was requested; missing `BRICKS-CONVERSION-NOTES.md` is not a failure for lean `/convert /bricks`;
- Bricks elements are non-empty, have IDs/names, and parent references resolve;
- `website/bricks-paste.html` remains page-only and contains no AppShell shell markup;
- source Seam classes and canonical Kiwe launchers are preserved in the conversion package;
- source `data-kiwe-query-template` markers have Bricks query settings or `fidelity.dynamicIntent`;
- bento/campaign/editorial grids, CSS grid columns/rows/spans, and Bricks responsive layout overrides have `fidelity.responsiveIntent` entries naming the breakpoint/range, source selector, mapped Bricks element IDs, Bricks controls, and intended grid/flex behavior; layout elements use `_direction:<breakpoint>`, non-nestable elements may use `_flexDirection:<breakpoint>`, and custom breakpoint keys exposed by Bricks/Site Graph are honored;
- CSS-heavy conversions include `fidelity.nativeStyleIntent` and enough native Bricks style/layout controls to keep the page editable in the visual builder instead of relying on one large custom-CSS dump;
- Bricks-native element settings and importable `global_classes` do not contain untokenized literal design lengths for spacing, sizing, radius, typography, shadows, transform offsets, or responsive layout controls; hardcoded `px/rem/em/vw/vh/...` values must be replaced by Kiwe/Seam variables, declared project variables, or real tokenized `clamp(...)` expressions, never `clamp(v, v, v)` no-op wrappers;
- Bricks-native element settings, importable `global_classes`, and custom CSS exceptions do not contain direct component color literals such as `#fff`, `#8deae5`, `rgb(...)`, `rgba(...)`, or local component variables assigned to literal colors; all component colors consume Kiwe/Seam variables or declared project variables, with literal colors limited to token/global variable definitions or `var(...)` fallbacks;
- `target.importMethod` is explicit; full pages and large sections use direct Bricks/My Templates upload or the Kiwe staging executor instead of clipboard JSON; direct Bricks/My Templates upload is claimed only when a native Bricks template export file exists and contains a title plus `content/header/footer` data and does not depend on page-level custom CSS for ordinary layout/design;
- Bricks dynamic tags and query-loop targets are verified against Site Graph when supplied;
- `_conditions` and `_interactions` are arrays and do not use unsafe JavaScript actions;
- unsupported visual/behavioral pieces are explicitly listed for manual review;
- no direct save/publish/write authority is claimed.

If tools are available, run:

```bash
node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs /path/to/handoff --site-graph /path/to/site-graph.json
# add --documented only when the command included /document
```

MCP clients should call `kiwe_validate_bricks_conversion`.

### `/apply /staging`

Use only with a target Kiwe site API key, explicit staging confirmation, and explicit mutation authorization.

This phase belongs to Kiwe controlled staging executor, not browser AI creativity.

## Preferred user workflow

For best output quality:

1. Ask any AI for a pure creative website/page draft. Do not mention Kiwe.
2. When the visual idea is good, run `/rebuild /seamframework`.
3. Run `/audit /seamframework`.
4. Create global design tokens with `/create /frameworkprofile` if needed, then import it in Kiwe > Framework and push to Bricks.
5. Audit tokens with `/audit /frameworkprofile`. Use `/create /brickstheme` only when the human specifically wants the standalone Bricks Theme Styles JSON.
6. Create the DSA theme with `/create /dsatheme`.
7. Audit the DSA theme with `/audit /dsatheme`.
8. Create or refresh DSA preview proof with `/create /preview /dsatheme` if needed.
9. Assemble with `/assemble /combined`.
10. Create or refresh the combined preview proof with `/create /preview /combined` if needed.
11. Audit with `/audit /combined`.
12. Add real WordPress/Bricks/WooCommerce bindings with `/usesitegraph`.
13. Convert only `website/bricks-paste.html` to a Bricks template upload JSON with `/convert /bricks`.
14. Audit conversion with `/audit /bricksconversion`.
15. Apply to staging only through Kiwe controlled executor.

For fast rough experiments, `/create /dsathemeandhomepage` or mode `combined` is allowed, but expect more audit cycles.

## Model behavior rule

When a command names a phase, do only that phase.

Do not opportunistically add DSA themes to Seam rebuilds, Bricks JSON to visual drafts, WooCommerce mutation to dynamic plans, or staging writes to audits.

If a route is unclear, ask for the missing artifact from the previous phase instead of guessing.
