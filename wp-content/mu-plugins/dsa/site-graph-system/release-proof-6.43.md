# Kiwe 6.43 release proof

Date: 2026-07-27

Scope:

- Seam/Bricks conversion hardening for National Chikki-style page outputs.
- Runtime dock material guard for split dock dark/light material behavior.
- AI Toolkit and Companion audit text alignment.

Evidence:

- Live review found the National Chikki Bricks page rail shrink was caused by Seam rail flow being applied to an outer nav/sticky wrapper instead of only the actual item track.
- `node kiwe-ai-toolkit/tools/audit-output.cjs C:\Users\shariq\Downloads\national-chikki-all-bricks-conversion\national-chikki-all-bricks-conversion` now rejects the affected package with `Seam rail flow is applied to the wrong wrapper`.
- The same audit now rejects project CSS declarations for bare Seam selectors, preventing future generated pages from restyling framework vocabulary directly.
- Runtime AppShell CSS now keeps split dock shell material transparent and applies solid/glass material to inactive dock controls, preserving Geometry Engine ownership of dock arrangement while making material selection visible.

Validation commands run locally:

- `node --check kiwe-ai-toolkit/tools/audit-output.cjs`
- `php -l wp-content/mu-plugins/dsa/includes/AI/AI_Companion_Service.php`
- `php -l wp-content/mu-plugins/dsa/includes/AI/Bricks_Conversion_Validator.php`
- `php -l wp-content/mu-plugins/dsa/includes/Public_Endpoint/Assets.php`
- `node tools/release/verify-package.cjs`
- `node tools/connector/ai-api-contracts.cjs`
- `node tools/release/rc12-contracts.cjs`
- `node tools/runtime/htmx-alpine-contracts.cjs`
