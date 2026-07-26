# Kiwe 6.46 release proof

## Scope

6.46 source-verifies and tightens the Bricks conversion fidelity gate against the local Bricks 2.4 beta source. The previous 6.45 rule correctly required responsive/bento intent, but it still assumed a mostly fixed breakpoint vocabulary and focused on `_direction:*`. Bricks 2.4 proves that responsive controls are emitted as `controlKey:breakpoint`, that custom breakpoint keys are valid, and that native direction controls may appear as `_flexDirection:<breakpoint>`.

## Bricks source evidence

- `C:/Users/shariq/Downloads/bricks.2.4-beta/bricks/includes/html-to-bricks/css-control-mapper.php` builds responsive keys with `get_responsive_control_key( $control_key, $breakpoint, $pseudo )`, yielding `controlKey:breakpoint`.
- `C:/Users/shariq/Downloads/bricks.2.4-beta/bricks/includes/html-to-bricks/control-index.generated.php` includes Bricks-native layout controls such as `_flexDirection`, `_gridTemplateColumns`, `_gridTemplateRows`, `_gridAutoFlow`, `_alignItemsGrid`, `_justifyContentGrid`, `_columnGap`, `_rowGap`, `_flexBasis`, `_margin`, `_padding`, `_aspectRatio`, and `_overflow`.
- `C:/Users/shariq/Downloads/bricks.2.4-beta/bricks/includes/breakpoints.php` confirms default breakpoint keys and custom breakpoint support.

## Contract changes

- Responsive layout override detection now accepts any Bricks-style `controlKey:breakpoint` suffix instead of only default breakpoint names.
- Native `_flexDirection:<breakpoint>` is treated as equivalent to copied `_direction:<breakpoint>` for seam-spread direction drift.
- Grid, flex, spacing, sizing, aspect-ratio, overflow, custom CSS, masonry, and custom breakpoint layout overrides all require `fidelity.responsiveIntent` when they appear in a conversion package.
- The same stricter rule is synchronized across the Node validator, audit CLI, REST validator, Companion review, Bricks AI context, Site Graph capability copy, and public toolkit contexts.

## Evidence

- `kiwe-ai-toolkit/tools/audit-output.cjs` SHA-256: `08ab497f265b689daae9550cce85060c16c801a8f74478458d4936d19350e671`
- `kiwe-ai-toolkit/lib/bricks-conversion-validator.js` SHA-256: `6038c49a140c78ee6545b933d687d31742ab33a34d2a57ec49ed126901d3cc2b`
- `wp-content/mu-plugins/dsa/includes/AI/Bricks_Conversion_Validator.php` SHA-256: `d71023f9966dfa44ce033db82955741f6e96c91aa31f47c507fb2cf5f2446507`
- `wp-content/mu-plugins/dsa/includes/AI/AI_Companion_Service.php` SHA-256: `158fe8560217e62c1d0e0b2c019a9715da0009783509f56b8831d7f56ba0322e`
- Added negative fixture: `kiwe-ai-toolkit/fixtures/bricks-conversion-invalid-flexdirection-responsive`.
- The negative fixture fails with exactly two blocking findings and zero warnings:
  - missing `fidelity.responsiveIntent`;
  - `_flexDirection:custom_phone` changes a `seam-spread` element to `column` without source-backed responsive intent.

## Commands run

```bash
node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs kiwe-ai-toolkit/fixtures/bricks-conversion-invalid-flexdirection-responsive
node --check kiwe-ai-toolkit/lib/bricks-conversion-validator.js
node --check kiwe-ai-toolkit/tools/audit-output.cjs
php -l wp-content/mu-plugins/dsa/includes/AI/Bricks_Conversion_Validator.php
php -l wp-content/mu-plugins/dsa/includes/AI/AI_Companion_Service.php
php -l wp-content/mu-plugins/dsa/includes/AI/Bricks_AI_Intelligence_Service.php
php -l wp-content/mu-plugins/dsa/includes/AI/Site_Graph_Service.php
node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs kiwe-ai-toolkit/fixtures/bricks-conversion-valid --site-graph kiwe-ai-toolkit/fixtures/bricks-conversion-valid/site-graph.json
node kiwe-ai-toolkit/tools/audit-output.cjs kiwe-ai-toolkit/fixtures/bricks-conversion-valid
node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs C:/Users/shariq/Downloads/national-chikki-bricksconversion-kiwe643-blockers-fixed/national-chikki-bricksconversion-kiwe643-blockers-fixed/homepage
node kiwe-ai-toolkit/tools/audit-output.cjs C:/Users/shariq/Downloads/national-chikki-bricksconversion-kiwe643-blockers-fixed/national-chikki-bricksconversion-kiwe643-blockers-fixed/homepage
npm --prefix kiwe-ai-toolkit test
node tools/connector/ai-api-contracts.cjs
node tools/release/rc12-contracts.cjs
node tools/runtime/htmx-alpine-contracts.cjs
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
```
