# Kiwe 6.39 release proof

Date: 2026-07-24

## Scope

This release promotes Seam into the public Appsite capability attribute layer.

Seam now has two live authoring lanes:

- semantic lane: roles, flows, tones, states, classes, IDs, ARIA, and tokens;
- capability lane: small builder-neutral Kiwe attributes that call Kiwe-owned runtime systems.

The goal is that `/rebuild /seamframework` can take a normal HTML/CSS/JS draft and add app-like behavior hooks without duplicating Kiwe runtime JavaScript.

## Live public attributes

- `data-dsa-open-module`
- `data-kiwe-save`
- `data-kiwe-save-id`
- `data-kiwe-save-title`
- `data-kiwe-save-url`
- `data-kiwe-save-image`
- `data-kiwe-notifications`
- `data-kiwe-notification-status-target`
- `data-kiwe-notification-topic`
- `data-dsa-native-notification-request`
- `data-kiwe-theme-toggle`
- `data-kiwe-theme-status-target`
- semantic page section IDs/labels for Kiwe Menu context fallback
- `data-kiwe-query-template`
- `data-kiwe-binding`

Candidate attributes such as share, compare, recently viewed, follow, AI context, feedback, and offer hooks remain roadmap-only until a later runtime contract marks them live.

## Surfaces updated

- Seam vocabulary PHP source and JSON/Markdown contract copies
- Kiwe Developer attribute reference
- Frontend runtime support for outside-dock `data-kiwe-theme-toggle`
- `/rebuild /seamframework`, `/audit /seamframework`, `/dynamic /sitegraph`, `/convert /bricks`, and `/audit /bricksconversion` contexts
- Toolkit CLI commands `kiwe attributes` and `kiwe seam-attributes-context`
- MCP tools `kiwe_list_capability_attributes` and `kiwe_get_seam_attributes_context`
- Bricks AI Intelligence context
- Bricks conversion validators and fixtures
- Companion context, answers, and conversion review checks
- Connector contract smoke checks

## Verification commands

```text
php -l wp-content/mu-plugins/dsa/includes/Design/Seam_Vocabulary_Schema.php
php -l wp-content/mu-plugins/dsa/includes/Admin/Admin.php
php -l wp-content/mu-plugins/dsa/includes/AI/AI_Companion_Service.php
php -l wp-content/mu-plugins/dsa/includes/AI/Bricks_AI_Intelligence_Service.php
php -l wp-content/mu-plugins/dsa/includes/AI/Bricks_Conversion_Validator.php
node --check wp-content/mu-plugins/dsa/assets/js/surface.js
node --check kiwe-ai-toolkit/lib/kiwe-core.js
node --check kiwe-ai-toolkit/bin/kiwe.js
node --check kiwe-ai-toolkit/mcp/index.js
node --check kiwe-ai-toolkit/lib/bricks-conversion-validator.js
npm.cmd test --prefix kiwe-ai-toolkit
node tools/connector/ai-api-contracts.cjs
node tools/release/build-package-manifest.cjs
node tools/release/verify-package.cjs
node tools/release/rc12-contracts.cjs
```
