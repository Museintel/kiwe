# /fix /accessibility

SeamFlow contract: 7.22

Canonical URL: https://start.kiwelaunch.com/fix/accessibility/
Machine contract: https://start.kiwelaunch.com/fix/accessibility/contract.json
Source hash: sha256:c36274d2436f38e3742e20ea6561722faa11b217a92cf06fc7e8686e62b3880b

## Phase

fix

## Requirements

- failed accessibility audit or existing artifact needing accessibility repair

## Required behavior

- Follow the canonical SeamFlow Start and command manifest.

## Outputs

- revised existing artifact file(s)
- accessibility/kiwe-accessibility-plan.json

## Forbidden

- new Bricks elements unless explicitly required and explained
- new classes unless official/project-token mapping cannot solve the failure
- removed Seam classes
- removed Kiwe/Appsite attributes
- DSA/AppShell structure changes
- Bricks reconversion
- website redesign
- docs unless /document
- ZIP

## Final response

Return fixed file(s), PASS/FAIL/WARN, files changed, and structural-drift summary. Keep it compact.
