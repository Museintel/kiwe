# Kiwe Bricks Conversion Lite Context

Use this context for `/convert /bricks` and `/audit /bricksconversion`.

This is not a creative design phase. It starts only after a website/page artifact is visually approved, after a Framework profile or Bricks theme style exists/pushed, and, when relevant, after `/usesitegraph` produced live binding intent.

Goal: convert an approved `website/bricks-paste.html` HTML/CSS page into a reviewable Bricks-native element JSON package without losing layout, Seam vocabulary, Kiwe launchers, dynamic tags, query-loop intent, conditions, interactions, or unsupported/manual-review evidence.

Hard boundary: `/convert /bricks` is page-only. Its source is `website/bricks-paste.html` and nothing else.

Never convert these lanes into Bricks:

- `combined-preview/index.html` or `combined-preview/assets/*`;
- `appshell-theme/preview/*`;
- `appshell-theme/import/*`;
- `theme-package.json`;
- `css/theme.css`;
- DSA/AppShell sheet, screen, dock, navbar, backdrop, fixture, or preview markup.

If a combined handoff is supplied, use only the `website/bricks-paste.html` lane as the conversion source. The AppShell theme remains a Kiwe theme package, not Bricks content.

`source.html` must point to `website/bricks-paste.html`.

Do not read the whole Kiwe repository. Do not scrape the public frontend. Do not mutate WordPress, Bricks, WooCommerce, cart, checkout, or auth. This phase produces a conversion package only.

If the artifact summary does not include `framework/kiwe-framework-profile.json`, `bricks-theme-style.json`, or explicit human confirmation that Kiwe > Framework/Bricks Theme Styles have already been imported/pushed, stop and ask the human to run `/create /frameworkprofile` first. Do not silently create a Framework profile inside `/convert /bricks`.

## Preferred inputs

- `website/bricks-paste.html`
- `framework/kiwe-framework-profile.json`, `bricks-theme-style.json`, or confirmation that Kiwe > Framework/Bricks Theme Styles are already pushed
- optional `bricks-bindings/kiwe-bindings.json` from `/usesitegraph`
- optional target `kiwe.site-graph.v1` JSON
- optional `/wp-json/dsa/v1/ai/bricks/context` or MCP `kiwe_get_bricks_conversion_context`
- optional `/usecompanion` for compact Kiwe/Bricks cards and deterministic review

When a target-site API key is available, ask:

```text
GET|POST /wp-json/dsa/v1/ai/bricks/context
POST     /wp-json/dsa/v1/ai/bricks/plan
GET|POST /wp-json/dsa/v1/ai/site-graph-data
POST     /wp-json/dsa/v1/ai/companion/context
POST     /wp-json/dsa/v1/ai/audit-companion/review
```

Use the Bricks context for real element names, element controls, query-loop object types, dynamic tags, conditions, interactions, and Kiwe launcher rules. Do not invent Bricks element names or dynamic tags when this context is available.

## Bricks 2.4 native conversion boundary

Bricks 2.4 ships a native HTML/CSS-to-Bricks conversion pipeline and AI Abilities. Prefer that native converter when the target site exposes it. It can parse HTML, map DOM nodes to Bricks elements, parse CSS, create global classes, extract variables, validate element data, and identify executable JavaScript.

Kiwe adds the part the raw converter cannot safely infer:

- source-to-element fidelity map;
- dynamic tag/query-loop intent;
- Kiwe DSA launcher preservation;
- Bricks conditions and interactions review;
- unsupported behavior list;
- no-mutation authority proof;
- Site Graph compatibility evidence.

## Required output

Keep the existing handoff files intact. Add:

```text
bricks-conversion/
  kiwe-bricks-conversion.json
bricks-template/
  [page-or-template-name]-template-upload.json   # required for Bricks My Templates/admin upload delivery
```

Do not emit `BRICKS-CONVERSION-NOTES.md`, README files, reports, screenshots, duplicate previews, or extra docs unless the command also includes `/document` or the human explicitly asks for documentation.

Exact primary file path: `bricks-conversion/kiwe-bricks-conversion.json`.

For full pages, large sections, or anything too large to comfortably paste into the Bricks front-end editor, `bricks-admin-template-upload` is the default delivery target. In that case the package must include both lanes: the Kiwe audit envelope above and a separate native Bricks template upload JSON in `bricks-template/`. The native template file is what humans upload to Bricks. The Kiwe envelope is what validators, Companion, and future staging executors inspect.

`kiwe-bricks-conversion.json` quick contract:

```json
{
  "schema": "kiwe.bricks-conversion.v1",
  "source": {
    "html": "website/bricks-paste.html",
    "css": "embedded-or-pageSettings",
    "sourceHash": "optional sha256 of the approved source"
  },
  "target": {
    "builder": "bricks",
    "format": "bricks-elements-json",
    "mode": "conversion-review",
    "importMethod": "review-only|bricks-clipboard-json|bricks-admin-template-upload|kiwe-staging-executor",
    "templateExportPath": "optional/path/to/native-bricks-template-export.json",
    "applyAuthority": "human-reviewed-kiwe-staging-adapter"
  },
  "conversion": {
    "converter": "bricks-native|kiwe-fallback|ai-authored|manual",
    "nativePreferred": true,
    "containsExecutableJs": false
  },
  "elements": [],
  "pageSettings": {},
  "globalClasses": [],
  "globalVariables": [],
  "fidelity": {
    "sourceSelectors": [],
    "elementMapping": [],
    "dynamicIntent": [],
    "responsiveIntent": [],
    "nativeStyleIntent": [],
    "interactions": [],
    "conditions": [],
    "unsupported": []
  },
  "report": {
    "summary": "",
    "manualReview": [],
    "lostFeatures": []
  }
}
```

## Conversion rules

- Use Bricks-native elements where they carry real semantics or runtime capability: `section`, `container`, `block`, `heading`, `text-basic`, `text-link`, `button`, `image`, `icon`, `form`, `accordion`, `tabs-nested`, product/post elements, query result elements, and other elements listed by `/ai/bricks/context`.
- Use neutral `div`/`block` only for real layout shells.
- Preserve classes, IDs, ARIA, public Seam attributes, `data-project-role`, and live Kiwe/Appsite capability attributes such as `data-dsa-open-module`, `data-kiwe-save`, `data-kiwe-notifications`, `data-kiwe-theme-toggle`, `data-kiwe-query-template`, and `data-kiwe-binding`. Do not convert DSA/AppShell theme markup or `appshell-theme/` CSS to Bricks; `/convert /bricks` is for website/page HTML only.
- Fail the conversion source if project CSS redefines Seam framework selectors such as `.seam-horizontal-rail`, `.seam-card`, `.seam-visually-hidden`, or `[data-flow="reel"]`, including scoped selectors like `.project .seam-card`. Seam selectors are shared vocabulary. Visual rules must live on project-owned classes before conversion, otherwise Bricks imports can inherit rail/card/grid behavior in the wrong place.
- Fail the conversion source if a wrapper/nav/sticky shell uses `.seam-horizontal-rail` or `data-flow="horizontal-rail"` while an inner descendant is the real rail track. Put rail flow only on the actual item track, not on the outer shell that contains `.seam-container`.
- Keep `website/bricks-paste.html` page-only. Do not put `data-dsa-surface`, dock, sheet, screen, or AppShell fixture markup into the Bricks page artifact.
- Do not use `combined-preview`, `appshell-theme`, `theme-package.json`, `theme.css`, dock markup, sheet markup, or screen markup as conversion source.
- Convert approved visual CSS into Bricks element settings, global classes, global variables, or safe page CSS. Do not hide the whole design in one giant Code element unless Bricks cannot represent it.
- Bricks conversion is an editable visual-builder handoff, not only a render handoff. Prefer Bricks-native controls for typography, color/background/gradient, border/radius, shadow, transform, filter, transition, spacing, sizing, grid/flex, responsive direction, alignment, conditions, interactions, query loops, and dynamic tags.
- Do not park project-wide variables, `@media` rules, bento/campaign CSS, or global selectors inside one element's `_cssCustom`. Use native Bricks controls, importable global classes, and global variables first. For review-only or small clipboard lanes, `pageSettings.customCss` may hold documented exceptions; for `bricks-admin-template-upload`, do not rely on `pageSettings.customCss` for ordinary page design because it may not travel when a template is inserted into a target page.
- For `bricks-admin-template-upload`, do not rely on `pageSettings.customCss` for ordinary page design. Bricks can store page settings on a template record, but insertion into a target page/template may not transfer that CSS the way a pasted page preview did; stale target-page CSS can then control the render. Put ordinary visual/layout decisions in native element settings, template-upload `global_classes`, and mapped/global variables. Keep only tiny documented CSS exceptions.
- Custom CSS is allowed only as an explicit exception for things Bricks cannot express cleanly, such as pseudo-elements, advanced masks, very specific media-query exceptions, unusual browser features, or reviewed micro-interactions. If custom CSS remains, explain why it remains and what native controls were used first.
- Preserve intentional CSS states and responsive behavior. If a pseudo-state, media query, mask, grid, interaction, or animation cannot be represented safely in Bricks controls, put it in `pageSettings.customCss` and list it under `fidelity.unsupported` or `report.manualReview`.
- Preserve complex layout intent, not just markup. Bento/editorial grids, campaign cards, CSS grid columns/rows/spans, and any Bricks breakpoint layout settings must be backed by `fidelity.responsiveIntent`. Bricks 2.4 stores responsive controls as `controlKey:breakpoint`, including defaults such as `_direction:mobile_landscape`, grid controls such as `_gridTemplateColumns:tablet_portrait`, `_cssCustom:<breakpoint>`, and site-defined custom breakpoint keys. Bricks layout elements (`container`, `div`, `section`, `block`) use `_direction` / `_direction:<breakpoint>` for flex direction; `_flexDirection` is for non-nestable elements only. Do not flip a source row/spread layout into a mobile column layout unless the source CSS/media query proves that breakpoint behavior.
- Executable JavaScript must not silently become production authority. Prefer Bricks interactions when safe, Kiwe capability attributes for Kiwe-owned journeys, or manual review.

## Bricks delivery/import method

`bricks-conversion/kiwe-bricks-conversion.json` is a Kiwe audit/executor artifact. It is not, by itself, a Bricks "My Templates" upload file.

Set `target.importMethod` explicitly:

- `review-only` when the package is for audit/planning only.
- `kiwe-staging-executor` when a trusted Kiwe site/API will create or update Bricks content after validation.
- `bricks-clipboard-json` only for small fragments where the human can reasonably paste the native copied-elements JSON into the Bricks editor. Do not use this as the default for full pages.
- `bricks-admin-template-upload` only when the package includes a separate native Bricks template export JSON at `target.templateExportPath`.

If `target.importMethod` is `bricks-admin-template-upload`, the template export file must match Bricks' own import shape: it must be a JSON object with a non-empty `title`, a `templateType`, and a non-empty `content`, `header`, or `footer` array. Use Bricks' template dependency key `global_classes` for global class rows that must import with the template; copied-elements style `globalClasses` alone is not enough for the My Templates upload path. Do not upload `kiwe-bricks-conversion.json` to Bricks My Templates; Bricks will import it as `(no title)` and then fail insertion with "This template has no data" because it is missing native `content/header/footer`.

The native Bricks template export must also carry the editable design itself. For full-page templates, Bricks-native element settings/global classes should include the layout and visual controls. A template whose `content` array is present but whose styling lives mainly in `pageSettings.customCss` is not a valid production-grade conversion, because it can import structurally and still render wrongly after insertion.
- Use query loops and dynamic tags only when verified by Site Graph or `/ai/bricks/context`.
- Do not convert placeholder product/category/media samples into hardcoded production content when a dynamic binding/query loop exists.
- Do not claim WordPress/Bricks/WooCommerce writes. The conversion package is reviewable input for the controlled staging executor.

## Fidelity map expectations

`fidelity.sourceSelectors` should list important source regions and where they landed:

```json
{
  "selector": "#hero",
  "intent": "hero section",
  "mappedElementIds": ["abc123"],
  "status": "mapped"
}
```

`fidelity.dynamicIntent` should connect placeholder regions to binding/query-loop intent:

```json
{
  "sourceSelector": "[data-kiwe-query-template=\"featured-products\"]",
  "bindingId": "featured-products",
  "bricksElementId": "def456",
  "queryLoop": "product_cat term from Site Graph",
  "status": "mapped"
}
```

`fidelity.interactions` and `fidelity.conditions` should describe Bricks `_interactions` and `_conditions` used, or state why behavior remains manual.

`fidelity.nativeStyleIntent` is required whenever the conversion carries substantial custom CSS or complex visual styling. It proves the page remains editable in Bricks. Each item should identify the selector, mapped element IDs, native Bricks controls/global classes/global variables used, and any remaining custom-CSS exception:

```json
{
  "sourceSelector": ".nc-campaign--hero",
  "mappedElementIds": ["f41933"],
  "nativeControls": ["_background", "_border", "_boxShadow", "_padding", "_heightMin", "_gridItemColumnSpan"],
  "customCssException": "decorative pseudo-element only",
  "status": "editable-native-first"
}
```

Native-first means the generated Bricks tree should use Bricks settings for ordinary visual decisions before CSS. Bricks 2.4 documents layout controls such as `_display`, `_direction`, `_justifyContent`, `_alignItems`, `_flexWrap`, `_columnGap`, `_rowGap`, grid controls such as `_gridTemplateColumns`, `_gridTemplateRows`, `_gridGap`, `_gridAutoFlow`, child span controls such as `_gridItemColumnSpan` and `_gridItemRowSpan`, and style controls such as `_typography`, `_background`, `_gradient`, `_border`, `_boxShadow`, `_transform`, `_cssFilters`, and `_cssTransition`. Breakpoint variants use `controlKey:breakpoint`, for example `_direction:mobile_landscape` or `_typography:mobile_portrait`.

Do not hide editable layout/design intent inside `pageSettings.customCss` just because it renders. If custom CSS contains many mappable declarations such as `display`, `flex-direction`, `grid-template-columns`, `gap`, `padding`, `margin`, `font-size`, `background`, `border-radius`, or `box-shadow`, the conversion must expose a matching amount of native Bricks controls/global classes/global variables. Custom CSS remains acceptable for explicit exceptions: pseudo-elements, complex masks, unsupported art-direction details, browser fallbacks, or tiny glue CSS listed in `fidelity.nativeStyleIntent`, `fidelity.unsupported`, or `report.manualReview`.

`fidelity.responsiveIntent` is required when the source/conversion uses bento, campaign/editorial grids, CSS grid placement, media-query layout changes, or Bricks responsive layout overrides. Use `_direction:<breakpoint>` for layout elements and `_flexDirection:<breakpoint>` only for non-nestable Bricks elements. Treat custom breakpoint suffixes as valid Bricks breakpoints when Site Graph or Bricks context exposes them. Each item should identify the breakpoint/range, source selector, mapped Bricks element IDs, Bricks controls, and preserved behavior:

```json
{
  "breakpoint": "mobile_landscape",
  "sourceSelector": "#home-campaigns .nc-bento",
  "mappedElementIds": ["f9055a", "f41933", "e1d9a2"],
  "behavior": "single-column bento; campaign cards keep readable min-block-size; section-head remains row/spread unless source CSS changes it"
}
```

For bento/campaign sections, `fidelity.sourceSelectors` must explicitly name the key layout selectors such as `#home-campaigns`, `.nc-bento`, `.nc-bento-side`, `.nc-bento-side-bottom`, and their card selectors. A conversion that simply lists “homepage section” but not the grid/card selectors has not proven visual fidelity.

## Audit

Use `/audit /bricksconversion` after conversion.

If tools are available, run:

```bash
node kiwe-ai-toolkit/tools/validate-bindings.cjs <handoff> --site-graph <site-graph.json>
node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs <handoff> --site-graph <site-graph.json>
```

MCP clients should call:

```text
kiwe_validate_bindings
kiwe_validate_bricks_conversion
```

If `/usecompanion` is present, submit the actual generated file map to:

```text
POST /wp-json/dsa/v1/ai/validate-bricks-conversion
POST /wp-json/dsa/v1/ai/audit-companion/review
```

Fix every `mustFix`, then rerun once when practical.

## Optional `/document` notes file

Create `BRICKS-CONVERSION-NOTES.md` only when the command includes `/document` or the human explicitly asks for notes. When requested, it should explain:

- source artifact converted;
- whether Bricks native converter, Kiwe fallback, or AI-authored mapping was used;
- Site Graph / Bricks context used;
- dynamic tags and query-loop mappings;
- conditions/interactions mapped;
- unsupported/manual-review items;
- confirmation that the package performs no mutation by itself;
- how a human or trusted Kiwe staging executor should apply it later.
