# /fix /accessibility

SeamFlow contract: 7.28

Canonical URL: https://start.kiwelaunch.com/fix/accessibility/
Machine contract: https://start.kiwelaunch.com/fix/accessibility/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.28-50e961d04b65/fix/accessibility/contract.json
Release: 7.28-50e961d04b65
Source hash: sha256:50e961d04b652c2e7e9cf1a768be366df73c4430814a82ce5ab4aeb604e96dc0

## Phase

fix

## Hosted resources

- https://start.kiwelaunch.com/contexts/accessibility-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.28-50e961d04b65/contexts/accessibility-lite.md
- https://start.kiwelaunch.com/contexts/seam-attributes-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.28-50e961d04b65/contexts/seam-attributes-lite.md

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
