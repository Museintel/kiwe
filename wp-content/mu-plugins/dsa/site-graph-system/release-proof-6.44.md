# Kiwe 6.44 release proof

Date: 2026-07-27

Scope:

- `/audit /bricksconversion` tightening after reviewing `national-chikki-bricksconversion-kiwe643-blockers-fixed`.
- Scoped Seam selector purity.
- Bricks 2.4 native `html` element recognition.

Evidence:

- The fixed National Chikki package passed the generic Kiwe 6.43 audit, but the dedicated Bricks conversion validator still rejected each `kiwe-bricks-conversion.json` for missing required root lanes: `target`, `conversion`, `fidelity.sourceSelectors`, and `report.manualReview`.
- The package still contained scoped project CSS targeting a Seam selector: `.nc-page .seam-visually-hidden`, proving 6.43 only caught selectors that began with `.seam-*`.
- 6.44 updates the audit and Companion/validator selector scan to reject scoped Seam selector declarations as well.
- 6.44 adds conversion JSON contract checks to the generic `audit-output.cjs` layer so a browser AI cannot claim `/audit /bricksconversion` success while skipping the dedicated Bricks conversion schema requirements.
- Local Bricks 2.4 beta contains `includes/elements/html.php`; 6.44 therefore recognizes `html` as a valid Bricks element in the conversion validator.

Validation commands run locally:

- `node --check kiwe-ai-toolkit/tools/audit-output.cjs`
- `node --check kiwe-ai-toolkit/lib/bricks-conversion-validator.js`
- `php -l wp-content/mu-plugins/dsa/includes/AI/AI_Companion_Service.php`
- `php -l wp-content/mu-plugins/dsa/includes/AI/Bricks_Conversion_Validator.php`
- `node kiwe-ai-toolkit/tools/audit-output.cjs C:\Users\shariq\Downloads\national-chikki-bricksconversion-kiwe643-blockers-fixed\national-chikki-bricksconversion-kiwe643-blockers-fixed\homepage`
- `node kiwe-ai-toolkit/tools/validate-bricks-conversion.cjs C:\Users\shariq\Downloads\national-chikki-bricksconversion-kiwe643-blockers-fixed\national-chikki-bricksconversion-kiwe643-blockers-fixed\homepage`

