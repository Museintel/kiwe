# Kiwe 6.45 release proof

## Scope

6.45 tightens the `/convert /bricks` and `/audit /bricksconversion` loop after a National Chikki bento hero import exposed a responsive layout fidelity gap. The rail-wrapper issue was already caught, but the conversion could still pass while bento/campaign grid behavior and Bricks breakpoint direction changes drifted from the approved HTML/CSS.

## Contract changes

- `fidelity.responsiveIntent` is now required when a Bricks conversion contains bento/campaign/editorial grids, CSS grid placement, media-query layout behavior, or Bricks responsive layout overrides.
- Complex bento/grid/campaign regions must be named in `fidelity.sourceSelectors`, including source selectors such as `#home-campaigns`, `.nc-bento`, `.nc-bento-side`, and related campaign card selectors when present.
- Bricks responsive layout overrides such as `_direction:mobile_landscape` must be tied to source evidence instead of silently changing row/spread/grid behavior.
- The same rule now exists in the standalone Node validator, generic audit tool, REST validator, Companion review path, Bricks AI context, and public lite contexts.

## Evidence

- `kiwe-ai-toolkit/tools/audit-output.cjs` SHA-256: `5117a31b354968d3701dc346239c608810860157ba459d2b5e1d5250193ad014`
- `kiwe-ai-toolkit/lib/bricks-conversion-validator.js` SHA-256: `c29cdb307fc27fd419c527667cf326d2ad3e6e21461f571c5749f3fca218add7`
- Known-good Bricks conversion fixture still passes `validate-bricks-conversion`.
- The National Chikki bento conversion now fails with explicit responsive/bento fidelity findings instead of only schema or generic CSS findings.

## Commands run

```bash
node --check kiwe-ai-toolkit/lib/bricks-conversion-validator.js
node --check kiwe-ai-toolkit/tools/audit-output.cjs
php -l wp-content/mu-plugins/dsa/includes/AI/Bricks_Conversion_Validator.php
php -l wp-content/mu-plugins/dsa/includes/AI/AI_Companion_Service.php
php -l wp-content/mu-plugins/dsa/includes/AI/Bricks_AI_Intelligence_Service.php
php -l wp-content/mu-plugins/dsa/includes/AI/Site_Graph_Service.php
node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs kiwe-ai-toolkit/fixtures/bricks-conversion-valid
node kiwe-ai-toolkit/tools/audit-output.cjs kiwe-ai-toolkit/fixtures/bricks-conversion-valid
node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs C:/Users/shariq/Downloads/national-chikki-bricksconversion-kiwe643-blockers-fixed/national-chikki-bricksconversion-kiwe643-blockers-fixed/homepage
node kiwe-ai-toolkit/tools/audit-output.cjs C:/Users/shariq/Downloads/national-chikki-bricksconversion-kiwe643-blockers-fixed/national-chikki-bricksconversion-kiwe643-blockers-fixed/homepage
```

