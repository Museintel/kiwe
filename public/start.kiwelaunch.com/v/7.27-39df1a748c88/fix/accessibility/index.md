# /fix /accessibility

SeamFlow contract: 7.27

Canonical URL: https://start.kiwelaunch.com/fix/accessibility/
Machine contract: https://start.kiwelaunch.com/fix/accessibility/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.27-39df1a748c88/fix/accessibility/contract.json
Release: 7.27-39df1a748c88
Source hash: sha256:39df1a748c886c29fb1f97434bef4dae6ab8b49a0a3736c4937a9fa9321f4410

## Phase

fix

## Hosted resources

- https://start.kiwelaunch.com/contexts/accessibility-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.27-39df1a748c88/contexts/accessibility-lite.md
- https://start.kiwelaunch.com/contexts/seam-attributes-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.27-39df1a748c88/contexts/seam-attributes-lite.md

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
