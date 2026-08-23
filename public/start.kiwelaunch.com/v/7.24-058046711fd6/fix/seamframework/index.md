# /fix /seamframework

SeamFlow contract: 7.24

Canonical URL: https://start.kiwelaunch.com/fix/seamframework/
Machine contract: https://start.kiwelaunch.com/fix/seamframework/contract.json
Pinned machine contract: https://start.kiwelaunch.com/v/7.24-058046711fd6/fix/seamframework/contract.json
Release: 7.24-058046711fd6
Source hash: sha256:058046711fd68bc5727b762dd38019ad3cd59ab429e80e509bf69128195ea69d

## Phase

fix

## Hosted resources

- https://start.kiwelaunch.com/contexts/workflow-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.24-058046711fd6/contexts/workflow-lite.md
- https://start.kiwelaunch.com/contexts/seam-attributes-lite.md
  - Pinned: https://start.kiwelaunch.com/v/7.24-058046711fd6/contexts/seam-attributes-lite.md
- https://start.kiwelaunch.com/validators/validate-seamframework.cjs
  - Pinned: https://start.kiwelaunch.com/v/7.24-058046711fd6/validators/validate-seamframework.cjs

## Requirements

- failed Seam page artifact and audit findings

## Required behavior

- fix the actual Seam page artifact
- move visual CSS off bare seam-* selectors and onto project-owned classes
- rerun the same Seam validator until PASS or NEEDS_INPUT

## Outputs

- website/bricks-paste.html

## Forbidden

- Bricks JSON
- DSA theme
- redesign
- docs unless /document
- ZIP

## Final response

Return fixed Seam page artifact and compact validator-proof status.
