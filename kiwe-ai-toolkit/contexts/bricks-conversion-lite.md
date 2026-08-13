# Kiwe Bricks Conversion Lite Context

Use this context for `/convert /bricks`, `/seamframework`, `/audit /bricksconversion`, and `/audit /seamframework` together with `seam-compiler-lite.md`.

This is not a creative design phase. It starts after an HTML/CSS/JS source is visually approved. A Framework Profile is never required for raw `/convert /bricks`; it is produced only by the optional post-conversion `/seamframework` stage.

Goal: deterministically convert an arbitrary approved HTML/CSS/JS project, folder, archive, or standalone document into native Bricks template JSON that a human can upload to Bricks > My Templates, without route-name assumptions or AI-authored production JSON.

Mode precedence:

- Raw `/convert /bricks` is Framework-neutral. Source literals and authored classes may remain native when that is required for 1:1 fidelity.
- Optional `/seamframework` emits one Framework Profile plus dependent templates. Only that mode requires token-pure values, Theme Style ownership, project variables/classes, and profile-before-template import.
- `/audit /bricksconversion` audits raw fidelity and native coverage. `/audit /seamframework` audits the profile and dependent templates as one package.
- When an older rule below mentions mandatory Framework variables, profile proof, token purity, or name-only global classes, apply it to `/seamframework` only—not raw Convert.

The deterministic SEAM Compiler is the production authority. Browser AI may explain findings but must not manually recreate Bricks JSON, Framework data, or validator PASS.

Source discovery is general-purpose. A complete standalone document may become three outputs: dedicated header, content, and footer templates. A multi-file project may produce any number of discovered page/template outputs. Do not assume only Home or Shop.

Do not convert Kiwe DSA/AppShell control chrome into page content when it is explicitly supplied as a separate AppShell lane:

Never convert these lanes into Bricks:

- `combined-preview/index.html` or `combined-preview/assets/*`;
- `appshell-theme/preview/*`;
- `appshell-theme/import/*`;
- `theme-package.json`;
- `css/theme.css`;
- DSA/AppShell sheet, screen, dock, navbar, backdrop, fixture, or preview markup.

If a combined handoff is supplied, discover the actual website source files and exclude the separately identified AppShell theme lane. The AppShell theme remains a Kiwe theme package, not Bricks content.

Do not read the whole Kiwe repository. Do not scrape the public frontend. Do not mutate WordPress, Bricks, WooCommerce, cart, checkout, or auth. This phase produces upload/review artifacts only.

Never stop raw Convert because a Framework Profile is absent. When `/seamframework` is explicitly requested, generate its profile deterministically and require the human to push that profile before importing its dependent templates.

## Live Bricks preview contamination gate

If the human supplies a live Bricks preview/builder URL as evidence, inspect the target page before judging the current template. A page can remain styled even after Kiwe > Framework, Bricks variables, classes, colors, and theme styles are cleared because Bricks also emits page/content CSS from the current WordPress page.

Check for:

- `#bricks-inline-css-page`, especially `/* PAGE: DESKTOP */ /* CUSTOM CSS */`;
- `#bricks-frontend-inline-inline-css`, `#dynamic-element-css`, and other `bricks-inline-css-*` style blocks;
- page settings `customCss` or `_cssCustom*` if page/template JSON is available;
- stale selectors from earlier imports, such as `.nc-*`, `.bv-*`, `.promo-card`, `.product-card`, `.screen`, `.bento`, `.quick-grid`, `.story-card`, or other project classes not proven by the current artifact;
- Kiwe runtime seed variables from `dsa-phantom-seed` or `dsa-seam-inline-css` versus Framework/Style Manager variables.

If stale page custom CSS or old matching selectors are present and are not part of the current attached artifact, return `ERROR: KIWE_STALE_BRICKS_PAGE_CSS`. Do not claim the new template is broken or visually passed from that contaminated preview. Retest on a blank page or on a page whose Bricks page settings custom CSS has been cleared.

## Preferred inputs

- arbitrary HTML/CSS/JS files, project folder, archive, or standalone document
- optional raw compiler result when the requested stage is `/seamframework`
- optional Framework Profile only when auditing or repairing an existing Framework package
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

## Bricks native conversion boundary

Kiwe production Bricks template uploads target the public Bricks 2.3.x My Templates importer/runtime unless Site Graph or the human explicitly confirms a newer public compatible Bricks version. Do not emit unreleased/beta `2.4` metadata as production proof. Prefer Bricks-native HTML/CSS conversion capabilities when the target site exposes them. A native converter can parse HTML, map DOM nodes to Bricks elements, parse CSS, create global classes, extract variables, validate element data, and identify executable JavaScript.

External converters such as Bricks' own HTML & CSS to Bricks feature, Code2Bricks-style tools, or third-party AI Bricks skills may be used as **candidate intake/scaffold**, not as the final Kiwe authority. Their output must be normalized through this lane before it can PASS. If an external converter returns a Bricks JSON file that stores normal page design in Code elements, giant `_cssCustom` buckets, generated fallback variables, unsupported version metadata, or class-only styling, return FAIL/review-only and explain the smallest normalization path.

Kiwe adds the part the raw converter cannot safely infer:

- source-to-element fidelity map;
- dynamic tag/query-loop intent;
- Kiwe DSA launcher preservation;
- Bricks conditions and interactions review;
- unsupported behavior list;
- no-mutation authority proof;
- Site Graph compatibility evidence.

## Required output

Keep the existing handoff files intact. Add only the requested Bricks artifact:

```text
bricks-template/
  [page-or-template-name]-template-upload.json
```

Do not emit `BRICKS-CONVERSION-NOTES.md`, README files, reports, screenshots, duplicate previews, or extra docs unless the command also includes `/document` or the human explicitly asks for documentation.

Exact primary upload path: `bricks-template/[page-or-template-name]-template-upload.json`.

For full pages, large sections, or anything too large to comfortably paste into the Bricks front-end editor, `bricks-admin-template-upload` is the default delivery target. The native template file is what humans upload to Bricks. If no-loss proof is needed, embed it in a top-level `kiwe` object inside the same upload JSON so the artifact stays one-file and Bricks can ignore the metadata.

Optional only when `/document` or a controlled executor explicitly asks for an external envelope:

```text
bricks-conversion/
  kiwe-bricks-conversion.json
```

That optional envelope is for Kiwe validators/Companion/staging executors. It is not the file a human uploads to Bricks.

Native Bricks template quick contract:

```json
{
  "title": "Home",
  "templateType": "content",
  "version": "2.3.7 or target Bricks version",
  "content": [],
  "pageSettings": {},
  "global_classes": [],
  "globalVariables": [],
  "kiwe": {
    "schema": "kiwe.bricks-template.v1",
    "source": { "html": "website/bricks-paste.html" },
    "target": { "builder": "bricks", "importMethod": "bricks-admin-template-upload" },
    "fidelity": {
      "sourceSelectors": [],
      "dynamicIntent": [],
      "responsiveIntent": [],
      "nativeStyleIntent": []
    },
    "report": { "manualReview": [] }
  }
}
```

For a homepage body, use `title: "Home"` and `templateType: "content"` unless the human asks for a different page name. Do not output a template named `(no title)`, and do not upload `kiwe-bricks-conversion.json` to Bricks.

Optional `kiwe-bricks-conversion.json` quick contract:

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
- Convert approved visual CSS into Bricks element settings, global variables, or safe page CSS. For full-page My Templates uploads, element-native controls are the render/edit owner; imported `global_classes` are semantic/name-only unless the human explicitly asked for a reusable class-library artifact. Do not hide the whole design in one giant Code element unless Bricks cannot represent it.
- A Code element with `javascriptCode`, `css`, `cssCode`, `code`, `html`, `php`, `executeCode`, or equivalent runtime/custom-code settings is a blocking failure in the normal production `/convert /bricks` lane unless it is explicitly marked as a review-only unsupported exception. Bricks documents this as a manual-review/security holding lane for pasted scripts/external styles; Kiwe must not convert that holding lane into production page authority.
- Bricks conversion is an editable visual-builder handoff, not only a render handoff. Prefer Bricks-native controls for typography, color/background/gradient, border/radius, shadow, transform, filter, transition, spacing, sizing, grid/flex, responsive direction, alignment, conditions, interactions, query loops, and dynamic tags.
- Bricks-native controls must remain Framework-token driven. When a value represents spacing, sizing, radius, typography scale, shadow geometry, transform offsets, responsive layout, or component color/paint, use `var(--kiwe-*)`, `var(--seam-*)`, a declared project variable, or a real tokenized `clamp(...)` expression where appropriate. Do not hardcode spacing/sizing values in element settings as bare `px/rem/em/vw/vh/...` literals; examples that must fail include `_padding: 28px`, `_border.radius: 24px`, `_heightMin: 390px`, `_typography.font-size: 2.35rem`, `_rowGap: 20px`, and `_transform.translateY: -7px`. No-op clamps such as `clamp(22px, 22px, 22px)` are still hardcoded values and must fail; promote stable art-direction constants into Framework/project tokens or create real fluid clamps with distinct min/preferred/max behavior. Literal `0`, percentages for intrinsic art direction, opacity, weights, unitless line-height, and ARIA/content values are not the target of this rule.
- Component colors must also be token-pure. Direct values such as `color: #fff`, `_background.color.raw: #8deae5`, `linear-gradient(#201b18, #514238)`, `rgba(255,255,255,.11)`, local CSS variable declarations such as `--pack-bg: #f5b942`, or CSS-variable fallbacks such as `var(--nc-pack-gold-bg, #f5b942)` are blocking failures in Bricks template JSON/global classes/custom CSS. Use official Kiwe/Seam variables or declared project variables without fallbacks instead, e.g. `color: var(--kiwe-color-text-inverse)` or `--pack-bg: var(--nc-pack-gold-bg)`. Literal colors are allowed only in the Kiwe Framework profile / Bricks variable definition layer, not in Bricks render-owner settings.
- Variable availability is part of the Bricks conversion contract. Bricks templates produced by SeamFlow are not standalone visual packages; they are paired with a Kiwe Framework profile that must be pushed before template import. Therefore every CSS variable consumed by Bricks-native element settings must be a bare variable reference with no inline fallback, for example `var(--kiwe-color-surface)`, `var(--nc-promo-min)`, or `minmax(var(--sf-hero-side-min), .65fr)`. Fallbacks such as `var(--kiwe-color-surface, #fff8ef)` are blocking failures because they hide missing Framework/profile setup and create ghost styling that survives after a designer removes classes or variables.
- Native Bricks variable records must store names without the CSS custom-property prefix. Bricks' own variable parser strips `--` from `:root` declarations and later emits the prefix during CSS compilation. Therefore template `global_variables`/`globalVariables` entries must use `"name": "kiwe-color-brand"` or `"name": "nc-app-max"`, never `"name": "--kiwe-color-brand"` or `"name": "--nc-app-max"`. If the JSON stores the leading dashes, Bricks compiles `----nc-app-max` while page controls consume `var(--nc-app-max, ...)`, producing a frontend that looks partially unstyled even though the template imports.
- Every project variable consumed by the Bricks template must also be declared in the paired `framework/kiwe-framework-profile.json` under `settings.tokens.project.variables` so Kiwe > Framework can push it to Bricks before the template is imported. Use the template `globalVariables` as helpful metadata only; the Framework profile is the durable install lane.
- Reserved Framework variable names are not permission to invent tokens. A value such as `var(--kiwe-type-2xs, .7rem)`, `var(--kiwe-letterspace-eyebrow, .13em)`, `var(--kiwe-radius-pill, 999px)`, or `var(--seam-color-primary, ...)` is a blocking failure unless that exact variable exists in Kiwe's universal token registry/runtime. If no official token exists, use an existing official token with matching meaning, declare a project variable such as `--nc-eyebrow-letterspace`, or formally add the token to the universal registry before claiming PASS.
- Every project class that is expected to become reusable styling belongs in `settings.tokens.project.classes` when it is part of the reusable project design system. The Bricks template may still carry `global_classes` for Bricks bookkeeping, but for full-page template uploads those rows should be semantic/name-only. Do not duplicate visual controls into template `global_classes` when element-native controls already own the render.
- Token definitions may themselves contain plain values when their behavior role is a named primitive, geometry input, content limit, or responsive guard. That is allowed only at the token-definition layer. Converted Bricks settings must consume those named tokens instead of repeating the plain values anonymously. Example: `--kiwe-grid-min-col: 240px` is valid; `_gridAutoColumns: minmax(250px, 1fr)` is not unless the `250px` is replaced by an official token, declared project token, or proven calculated clamp.
- Tokenization fallback ladder:
  1. Prefer exact official Kiwe/Seam universal tokens when the value meaning and CSS property domain match. Do not map by number alone: `12px` padding may be spacing, but `12px` radius is not a spacing token.
  2. If the value is stable project art direction, declare a project variable in the paired Framework profile and consume it as `var(--project-token)` with no fallback. Brand/story-specific values belong here, not in the universal library.
  3. If the source proves different responsive values for the same property, replace breakpoint jumps with a real fluid clamp. Formula: `slope = (maxValue - minValue) / (maxViewport - minViewport) * 100`; `intercept = minValue - (slope / 100 * minViewport)`; output `clamp(minValue, calc(intercept + slope * 1vw), maxValue)`. Use `kiwe fluid-clamp --min 220px --max 390px --min-vw 478 --max-vw 1440` when shell/MCP access is available.
  4. If units are incompatible or intent is unclear, use a declared project token plus manual-review evidence instead of guessing.
- Do not park project-wide variables, `@media` rules, bento/campaign CSS, or global selectors inside one element's `_cssCustom`. Use native Bricks controls, importable global classes, and global variables first. For review-only or small clipboard lanes, `pageSettings.customCss` may hold documented exceptions; for `bricks-admin-template-upload`, do not rely on `pageSettings.customCss` for ordinary page design because it may not travel when a template is inserted into a target page.
- For `bricks-admin-template-upload`, do not rely on `pageSettings.customCss` for ordinary page design. Bricks can store page settings on a template record, but insertion into a target page/template may not transfer that CSS the way a pasted page preview did; stale target-page CSS can then control the render. Put ordinary visual/layout decisions in native element settings and mapped/global variables. Keep only tiny documented CSS exceptions.
- For `bricks-admin-template-upload`, every visual class emitted under `global_classes` must be collision-safe. Bricks skips/remaps imported global class styles when the target site already has the same class `id` or `name`; therefore project visual global classes must be namespaced, such as `nc-promo-card`, `nc-screen-heading`, `bv-product-card`, `sf-hero-grid`, or `kiwe-commerce-rail`. Do not put bare names like `promo-card`, `screen`, `display`, `eyebrow`, `pill`, `product-card`, `save-btn`, `rating`, `quick-grid`, or `story-card` in importable `global_classes`. Keep those plain semantic names only in `_cssClasses` or attributes if needed.
- For `bricks-admin-template-upload`, use Bricks source-backed control keys. Max/min sizing controls are `_widthMin`, `_widthMax`, `_heightMin`, and `_heightMax`; do not emit `_minWidth`, `_maxWidth`, `_minHeight`, or `_maxHeight`, because those can remain in JSON while Bricks silently omits the CSS rule. Likewise, do not put CSS-variable font stacks inside `_typography.font-family`; Bricks quotes typography font-family values during CSS output, which turns `var(--kiwe-font-body, ...)` into an invalid literal font family. Use concrete Bricks font-family values in `_typography` and keep tokenized font decisions in the Framework/theme profile. Semantic Bricks Heading elements (`name: "heading"` with `tag: "h1"` through `tag: "h6"`) must not carry local `_typography.font-size` values that reference official heading scale tokens such as `var(--kiwe-type-h3, 2rem)`. H1-H6 scale belongs in the pushed Framework profile / Bricks Theme Style, so a designer changing an element from h3 to h2 or h4 in Bricks gets the selected heading level rather than a stale local size lock. Component-specific display/card-title styling may use project classes/tokens, but it must not masquerade as the semantic heading scale. Bricks color controls must use Bricks color objects, not plain strings: `_background.color.raw`, `_border.color.raw`, and `_typography.color.raw`. Gradients belong in the native `_gradient` control with tokenized color stops; do not place `linear-gradient(...)` in `_background.color`, because Bricks compiles that lane to `background-color`. Bricks border radius controls must use `_border.radius.top`, `_border.radius.right`, `_border.radius.bottom`, and `_border.radius.left`; do not emit CSS corner keys like `topLeft`, `topRight`, `bottomRight`, or `bottomLeft`, because Bricks ignores them during frontend CSS generation.
- Custom CSS is allowed only as an explicit exception for things Bricks cannot express cleanly, such as pseudo-elements, advanced masks, very specific media-query exceptions, unusual browser features, or reviewed micro-interactions. If custom CSS remains, explain why it remains and what native controls were used first.
- Preserve intentional CSS states and responsive behavior. If a pseudo-state, media query, mask, grid, interaction, or animation cannot be represented safely in Bricks controls, put it in `pageSettings.customCss` and list it under `fidelity.unsupported` or `report.manualReview`.
- Preserve complex layout intent, not just markup. Bento/editorial grids, campaign cards, CSS grid columns/rows/spans, and any Bricks breakpoint layout settings must be backed by `fidelity.responsiveIntent`. Bricks responsive controls are stored as `controlKey:breakpoint`, including defaults such as `_direction:mobile_landscape`, grid controls such as `_gridTemplateColumns:tablet_portrait`, `_cssCustom:<breakpoint>`, and site-defined custom breakpoint keys. Bricks layout elements (`container`, `div`, `section`, `block`) use `_direction` / `_direction:<breakpoint>` for flex direction; `_flexDirection` is for non-nestable elements only. Do not flip a source row/spread layout into a mobile column layout unless the source CSS/media query proves that breakpoint behavior.
- Bricks-native layout controls must not rely on browser defaults. If a layout element sets `_display:"flex"`, it must also set `_direction` at the owning breakpoint. If it sets `_display:"grid"`, it must set `_gridTemplateColumns` or `_gridAutoColumns`. If an element is the actual `.seam-horizontal-rail` / `data-flow="horizontal-rail"` item track, it must set `_display:"flex"`, `_direction:"row"`, `_overflow:"auto"` or `"scroll"`, and a tokenized `_columnGap` or `_gap`. This is the exact class of drift where a category rail imports as stacked cards even though the source HTML looked correct.
- Executable JavaScript must not silently become production authority. Prefer Bricks interactions when safe, Kiwe capability attributes for Kiwe-owned journeys, or manual review.

## Bricks delivery/import method

`bricks-template/[page-or-template-name]-template-upload.json` is the default human upload artifact. It must be a native Bricks template export, not a Kiwe wrapper.

When an external `bricks-conversion/kiwe-bricks-conversion.json` envelope is present, set `target.importMethod` explicitly:

- `review-only` when the package is for audit/planning only.
- `kiwe-staging-executor` when a trusted Kiwe site/API will create or update Bricks content after validation.
- `bricks-clipboard-json` only for small fragments where the human can reasonably paste the native copied-elements JSON into the Bricks editor. Do not use this as the default for full pages.
- `bricks-admin-template-upload` only when the package includes or points to a native Bricks template export JSON at `target.templateExportPath`.

The template export file must match Bricks' own import shape: it must be a JSON object with a non-empty `title`, a `templateType`, and a non-empty `content`, `header`, or `footer` array. Use Bricks' template dependency key `global_classes` for global class rows that must import with the template; copied-elements style `globalClasses` alone is not enough for the My Templates upload path. Do not upload `kiwe-bricks-conversion.json` to Bricks My Templates; Bricks will import it as `(no title)` and then fail insertion with "This template has no data" because it is missing native `content/header/footer`.

The native Bricks template export must also carry the editable design itself. For full-page templates, Bricks-native element settings should include the layout and visual controls, and those controls must point back to Kiwe/Seam tokens rather than frozen pixel/rem values. A template whose `content` array is present but whose styling lives mainly in `pageSettings.customCss` is not a valid production-grade conversion, because it can import structurally and still render wrongly after insertion. A template whose design relies mainly on `global_classes` hydration is also not production-grade: Bricks My Templates can skip or remap global classes when the same class names already exist on the target site, so enough element-level native controls must remain on full-page templates for resilient rendering and visual-editor editability. The opposite failure must also be caught: a template must not duplicate visual styling into both element-native controls and styled imported `global_classes`, because that creates ghost styling after a designer edits/removes one layer. For full-page template uploads, `global_classes` should be semantic/name-only and reusable styled classes should come from the Framework profile push or a separate class-library artifact. Likewise, a template that uses inline CSS-variable fallbacks such as `var(--token, fallback)` is not production-grade: the required workflow is Kiwe > Framework profile push first, then Bricks template import, so missing variable setup is caught instead of hidden.
- Use query loops and dynamic tags only when verified by Site Graph or `/ai/bricks/context`.
- Do not convert placeholder product/category/media samples into hardcoded production content when a dynamic binding/query loop exists.
- Do not claim WordPress/Bricks/WooCommerce writes. The template is importable input for Bricks, and any controlled staging executor work remains a separate explicitly authorized phase.

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

Native-first means the generated Bricks tree should use Bricks settings for ordinary visual decisions before CSS. Bricks documents layout controls such as `_display`, `_direction`, `_justifyContent`, `_alignItems`, `_flexWrap`, `_columnGap`, `_rowGap`, grid controls such as `_gridTemplateColumns`, `_gridTemplateRows`, `_gridGap`, `_gridAutoFlow`, child span controls such as `_gridItemColumnSpan` and `_gridItemRowSpan`, and style controls such as `_typography`, `_background`, `_gradient`, `_border`, `_boxShadow`, `_transform`, `_cssFilters`, and `_cssTransition`. Breakpoint variants use `controlKey:breakpoint`, for example `_direction:mobile_landscape` or `_typography:mobile_portrait`.

Do not hide editable layout/design intent inside `pageSettings.customCss` just because it renders. If custom CSS contains many mappable declarations such as `display`, `flex-direction`, `grid-template-columns`, `gap`, `padding`, `margin`, `font-size`, `background`, `border-radius`, or `box-shadow`, the conversion must expose a matching amount of native Bricks controls/global classes/global variables. Custom CSS remains acceptable for explicit exceptions: pseudo-elements, complex masks, unsupported art-direction details, browser fallbacks, or tiny glue CSS listed in `fidelity.nativeStyleIntent`, `fidelity.unsupported`, or `report.manualReview`.

Native-first does not mean hardcoded-native. Element settings for padding, radius, min-height, mobile breakpoint values, typography sizes, gaps, shadow geometry, color, background, gradient, border color, and decorative pack colors must be tokenized. The Framework profile supplies the token definitions; the Bricks conversion consumes them. Fixing the profile alone does not fix hardcoded Bricks JSON. `/fix /bricksconversion` must not silence the validator with `clamp(v, v, v)`, by moving direct colors into local component variables, or by duplicating styled `global_classes` on top of element-native controls. Correct fixes are official universal tokens, declared project variables, real fluid clamps calculated from proven responsive states, and a single visual owner per property.

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
