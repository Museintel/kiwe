# Kiwe 0.6.36 release proof

This release adds the Kiwe Bricks conversion lane for `/convert /bricks` and `/audit /bricksconversion`.

## What changed

- Added `kiwe-ai-toolkit/contexts/bricks-conversion-lite.md` so browser, IDE, and MCP agents can convert approved HTML/CSS/JS page artifacts into reviewable Bricks-native packages without reading the whole plugin.
- Added `kiwe-ai-toolkit/lib/bricks-conversion-validator.js`, CLI `validate-bricks-conversion`, MCP `kiwe_validate_bricks_conversion`, and a valid fixture that proves source Seam classes, Kiwe launchers, query-loop intent, dynamic tags, and fidelity maps survive conversion.
- Added `DSA\AI\Bricks_Conversion_Validator` plus `/wp-json/dsa/v1/ai/validate-bricks-conversion`, API key scope `validate_bricks_conversion`, WordPress Ability `dsa/validate-bricks-conversion`, Site Graph connector discovery, and Bricks AI conversion context.
- Extended Companion/Audit Companion so `/usecompanion` can act as a deterministic Bricks conversion oracle and review gate without becoming a creative co-author or direct Bricks saver.

## Authority boundary

Bricks conversion packages are review artifacts, not mutation authority. They may describe Bricks elements, page settings, global classes/variables, query loops, dynamic tags, conditions, interactions, unsupported behavior, and source-to-element fidelity, but they do not save Bricks content, publish WordPress pages, mutate WooCommerce, run cart/checkout/auth, or own Kiwe AppShell runtime.

Actual staging remains behind the controlled executor and explicit confirmations.

## Verification

- `php -l wp-content/mu-plugins/dsa/includes/AI/AI_Companion_Service.php`
- `php -l wp-content/mu-plugins/dsa/includes/AI/Access_Key_Service.php`
- `php -l wp-content/mu-plugins/dsa/includes/AI/Bricks_AI_Intelligence_Service.php`
- `php -l wp-content/mu-plugins/dsa/includes/AI/Bricks_Conversion_Validator.php`
- `php -l wp-content/mu-plugins/dsa/includes/AI/Internal_AI_Context_Service.php`
- `php -l wp-content/mu-plugins/dsa/includes/AI/Site_Graph_Service.php`
- `php -l wp-content/mu-plugins/dsa/includes/Rest/AI_Access_Controller.php`
- `php -l wp-content/mu-plugins/dsa/includes/WP7/Abilities_Service.php`
- `node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs kiwe-ai-toolkit/fixtures/bricks-conversion-valid --site-graph kiwe-ai-toolkit/fixtures/bricks-conversion-valid/site-graph.json`
- `npm.cmd test --prefix kiwe-ai-toolkit`
- `node tools/release/verify-package.cjs`
- `node tools/connector/ai-api-contracts.cjs`
- `node tools/release/rc12-contracts.cjs`
