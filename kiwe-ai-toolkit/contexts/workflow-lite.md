# Kiwe AI workflow-lite context

Use this file when the human wants better AI output quality, fewer correction loops, or a slash-command style Kiwe workflow.

Do not read the full Kiwe repository. The goal is to route the AI into the smallest useful phase instead of asking one model turn to be maximally creative, framework-compliant, Bricks-aware, AppShell-safe, and audit-ready all at once.

## Core principle

Creativity and contract compliance are different jobs.

Run them as separate phases:

1. Pure creative draft.
2. Seam rebuild.
3. Seam audit.
4. Framework / Bricks global theme-style profile.
5. DSA AppShell theme.
6. DSA audit.
7. Combined assembly when both lanes are approved.
8. Dynamic WordPress / Bricks / WooCommerce binding after the visual handoff passes.
9. Bricks conversion package after dynamic intent is approved.
10. Bricks conversion audit.
11. Controlled staging apply only when a trusted Kiwe site executor is explicitly authorized.

This pipeline is preferred over one giant `combined` prompt for serious work.

## Human-facing command vocabulary

Humans should be able to write short commands. The toolkit supplies the rules.

Canonical creation verb: `/create`.

Do not teach mixed verbs such as `/build` for Kiwe handoff phases. If an older model or human writes `/build`, treat it as a legacy alias internally and answer back with the canonical `/create` command name.

Canonical preview commands:

```text
/create /preview /dsatheme
/create /preview /combined
```

`/create /preview /dsatheme` is only for the AppShell theme preview lane. `/create /preview /combined` is only for the primary combined preview lane. Neither command creates Bricks JSON, and neither preview is valid input for `/convert /bricks`.

## Command gate / no-waste boundary

Before doing real work for any slash command, validate the command cheaply.

Tool-capable clients should call:

```text
kiwe_diagnose_command
```

CLI-capable clients can run:

```bash
node kiwe-ai-toolkit/bin/kiwe.js diagnose --command "/convert /bricks" --artifact-summary "website/bricks-paste.html exists"
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
- `/convert /bricks` against `combined-preview` or `appshell-theme` -> `rejected`, `bricks_convert_forbidden_source_in_command`.
- `/audit /bricksconversion` without `bricks-conversion/kiwe-bricks-conversion.json` -> `needs_input`, `bricks_audit_missing_conversion_artifact`.
- `/dynamic /sitegraph` without Site Graph/API context -> `needs_input`, `dynamic_missing_site_graph`.
- `/apply /staging` without explicit staging confirmation/mutation authority -> `needs_input`, `staging_missing_explicit_authority`.

## Optional `/usecompanion` flag

`/usecompanion` can be appended to any workflow command:

```text
/rebuild /seamframework /usecompanion
/audit /dsatheme /usecompanion
/create /preview /dsatheme /usecompanion
/create /preview /combined /usecompanion
/dynamic /sitegraph /usecompanion
/convert /bricks /usecompanion
/audit /bricksconversion /usecompanion
```

This flag means: use Kiwe Companion if it is available, then continue the selected phase. It must never become a blocker.

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
- Keep production behavior minimal and framework-safe.
- Do not add DSA AppShell markup.
- Do not add Bricks JSON.

Expected output:

```text
website/
  bricks-paste.html
  bricks-notes.md
```

`website/bricks-paste.html` is both the preview and the Bricks paste/import artifact.

### `/audit /seamframework`

Use after a Seam rebuild.

Audit for:

- official Seam roles only;
- useful Seam Class Vocabulary usage;
- no custom `data-role` values;
- no duplicated app capabilities such as cart/auth/search/save/AI authority;
- no frontend scraping dependency;
- Bricks-friendly HTML/CSS;
- readable responsive spacing;
- no horizontal viewport overflow except intentional rails;
- no hardcoded production behavior that belongs to WordPress, Bricks, WooCommerce, or Kiwe.

If tools are available, run the relevant Kiwe validators. If tools are not available, revise the actual files manually and report what changed.

### `/create /brickstheme`

Alias: `/create /frameworkprofile`.

Use after the Seam page direction is approved and the human wants the site personality turned into reusable Kiwe / Bricks global tokens.

Create a standalone Framework profile only when the output is website/page-first and not an AppShell theme package.

Expected output:

```text
framework/
  kiwe-framework-profile.json
  FRAMEWORK-NOTES.md
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
      "bricks_theme_style": {}
    }
  }
}
```

Rules:

- Use official Kiwe universal token names only, such as `color-brand`, `color-accent`, `color-surface`, `color-text`, `font-display`, `font-body`, `type-h1`, `space-md`, `radius-lg`, and `shadow-md`.
- `bricks_theme_style` may cover global colors, typography, links, and site background only.
- Do not put AppShell dock/sheet/screen settings, products, posts, raw Bricks JSON, WooCommerce behavior, or runtime JS here.

### `/audit /brickstheme`

Alias: `/audit /frameworkprofile`.

Audit for:

- `schema: "kiwe.framework-profile.v1"`;
- `settings.tokens` only;
- official Kiwe token names only;
- no raw `--kiwe-*` or private `--dsa-runtime-token-*` keys;
- no AppShell settings;
- no Bricks element-level styling;
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
    bricks-notes.md
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

### `/dynamic /sitegraph`

Use only after the visual handoff passes.

Purpose:

- Convert approved static rails/cards/buttons into a dynamic binding plan using real WordPress, Bricks, WooCommerce, and Kiwe Site Graph facts.
- Use query loops, dynamic tags, conditions, interactions, and Kiwe launchers where the target Site Graph/Bricks context supports them.
- Do not guess product categories, page slugs, post types, custom fields, dynamic tags, or Bricks query-loop object types.
- Do not mutate the site.

Expected output:

```text
bricks-bindings/
  kiwe-bindings.json
  BINDING-NOTES.md
```

If tools are available:

```bash
node kiwe-ai-toolkit/tools/validate-bindings.cjs /path/to/handoff --site-graph /path/to/site-graph.json
node kiwe-ai-toolkit/tools/prepare-apply-plan.cjs /path/to/handoff --site-graph /path/to/site-graph.json
```

`prepare-apply-plan` is dry-run planning, not mutation.

### `/convert /bricks`

Use only after the website/page visual artifact passes and, when the page should use live WordPress/Bricks/WooCommerce data, after `/dynamic /sitegraph` has mapped that intent.

Purpose:

- Convert the approved `website/bricks-paste.html` artifact into a reviewable Bricks-native element JSON package.
- Preserve the approved layout, hierarchy, classes, IDs, ARIA, official Seam roles/classes, `data-seam-*`, `data-project-role`, and canonical Kiwe launchers such as `data-dsa-open-module`.
- Prefer Bricks 2.4 native HTML/CSS-to-Bricks conversion when the target exposes it.
- Carry Kiwe's no-loss proof for query loops, dynamic tags, conditions, interactions, unsupported features, and manual-review gates.
- Do not mutate WordPress, Bricks, WooCommerce, cart, checkout, or auth.
- Do not convert `combined-preview`, `appshell-theme`, DSA/AppShell theme packages, screen/sheet/dock/navbar markup, `theme-package.json`, or `css/theme.css`.

Expected output:

```text
bricks-conversion/
  kiwe-bricks-conversion.json
  BRICKS-CONVERSION-NOTES.md
```

The conversion JSON uses `schema: "kiwe.bricks-conversion.v1"` and contains top-level `source`, `target`, `conversion`, `elements`, `pageSettings`, `globalClasses`, `globalVariables`, `fidelity`, and `report` lanes.

`source.html` must point to `website/bricks-paste.html`.

Use `/wp-json/dsa/v1/ai/bricks/context` or MCP `kiwe_get_bricks_conversion_context` when available. That context describes real Bricks elements, query loops, dynamic tags, conditions, interactions, and the Kiwe conversion package.

### `/audit /bricksconversion`

Use after `/convert /bricks`.

Audit for:

- `bricks-conversion/kiwe-bricks-conversion.json` exists and uses `kiwe.bricks-conversion.v1`;
- `BRICKS-CONVERSION-NOTES.md` exists;
- Bricks elements are non-empty, have IDs/names, and parent references resolve;
- `website/bricks-paste.html` remains page-only and contains no AppShell shell markup;
- source Seam classes and canonical Kiwe launchers are preserved in the conversion package;
- source `data-kiwe-query-template` markers have Bricks query settings or `fidelity.dynamicIntent`;
- Bricks dynamic tags and query-loop targets are verified against Site Graph when supplied;
- `_conditions` and `_interactions` are arrays and do not use unsafe JavaScript actions;
- unsupported visual/behavioral pieces are explicitly listed for manual review;
- no direct save/publish/write authority is claimed.

If tools are available, run:

```bash
node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs /path/to/handoff --site-graph /path/to/site-graph.json
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
4. Create global design tokens with `/create /brickstheme` if needed.
5. Audit tokens with `/audit /brickstheme`.
6. Create the DSA theme with `/create /dsatheme`.
7. Audit the DSA theme with `/audit /dsatheme`.
8. Create or refresh DSA preview proof with `/create /preview /dsatheme` if needed.
9. Assemble with `/assemble /combined`.
10. Create or refresh the combined preview proof with `/create /preview /combined` if needed.
11. Audit with `/audit /combined`.
12. Add real WordPress/Bricks/WooCommerce bindings with `/dynamic /sitegraph`.
13. Convert only `website/bricks-paste.html` to Bricks with `/convert /bricks`.
14. Audit conversion with `/audit /bricksconversion`.
15. Apply to staging only through Kiwe controlled executor.

For fast rough experiments, `/create /dsathemeandhomepage` or mode `combined` is allowed, but expect more audit cycles.

## Model behavior rule

When a command names a phase, do only that phase.

Do not opportunistically add DSA themes to Seam rebuilds, Bricks JSON to visual drafts, WooCommerce mutation to dynamic plans, or staging writes to audits.

If a route is unclear, ask for the missing artifact from the previous phase instead of guessing.
