# Kiwe Bricks Conversion Lite Context

Use this context for `/convert /bricks` and `/audit /bricksconversion`.

This is not a creative design phase. It starts only after a website/page artifact is visually approved and, when relevant, after `/dynamic /sitegraph` produced live binding intent.

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

## Preferred inputs

- `website/bricks-paste.html`
- optional `bricks-bindings/kiwe-bindings.json` from `/dynamic /sitegraph`
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
  BRICKS-CONVERSION-NOTES.md
```

Exact primary file path: `bricks-conversion/kiwe-bricks-conversion.json`.

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
- Preserve classes, IDs, ARIA, `data-role`, `data-seam-*`, `data-project-role`, and canonical Kiwe launchers such as `data-dsa-open-module="cart"`.
- Keep `website/bricks-paste.html` page-only. Do not put `data-dsa-surface`, dock, sheet, screen, or AppShell fixture markup into the Bricks page artifact.
- Do not use `combined-preview`, `appshell-theme`, `theme-package.json`, `theme.css`, dock markup, sheet markup, or screen markup as conversion source.
- Convert approved visual CSS into Bricks element settings, global classes, global variables, or safe page CSS. Do not hide the whole design in one giant Code element unless Bricks cannot represent it.
- Preserve intentional CSS states and responsive behavior. If a pseudo-state, media query, mask, grid, interaction, or animation cannot be represented safely in Bricks controls, put it in `pageSettings.customCss` and list it under `fidelity.unsupported` or `report.manualReview`.
- Executable JavaScript must not silently become production authority. Prefer Bricks interactions when safe, Kiwe launchers for Kiwe capabilities, or manual review.
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

## Notes file

`BRICKS-CONVERSION-NOTES.md` must explain:

- source artifact converted;
- whether Bricks native converter, Kiwe fallback, or AI-authored mapping was used;
- Site Graph / Bricks context used;
- dynamic tags and query-loop mappings;
- conditions/interactions mapped;
- unsupported/manual-review items;
- confirmation that the package performs no mutation by itself;
- how a human or trusted Kiwe staging executor should apply it later.
