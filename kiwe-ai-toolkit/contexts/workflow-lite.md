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
9. Controlled staging apply only when a trusted Kiwe site executor is explicitly authorized.

This pipeline is preferred over one giant `combined` prompt for serious work.

## Human-facing command vocabulary

Humans should be able to write short commands. The toolkit supplies the rules.

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
8. Assemble with `/assemble /combined`.
9. Audit with `/audit /combined`.
10. Add real WordPress/Bricks/WooCommerce bindings with `/dynamic /sitegraph`.
11. Apply to staging only through Kiwe controlled executor.

For fast rough experiments, `/build /dsathemeandhomepage` or mode `combined` is allowed, but expect more audit cycles.

## Model behavior rule

When a command names a phase, do only that phase.

Do not opportunistically add DSA themes to Seam rebuilds, Bricks JSON to visual drafts, WooCommerce mutation to dynamic plans, or staging writes to audits.

If a route is unclear, ask for the missing artifact from the previous phase instead of guessing.
